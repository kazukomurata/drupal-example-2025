<?php

namespace Drupal\Tests\site_notice\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\site_notice\Plugin\Block\SiteNoticeBlock;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[Group('site_notice')]
class SiteNoticeBlockTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    // Drupal::service('cache_contexts_manager') を使用する箇所を満たすため、
    // 最小のコンテナをセットする（assertValidTokens() だけ返すスタブ）。
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', new class() {
      public function assertValidTokens(array $contexts): bool { return TRUE; }
    });
    \Drupal::setContainer($container);
  }

  /**
   * Creates a block instance with provided configuration and mocked time.
   */
  private function createBlock(array $configuration, int $now): SiteNoticeBlock {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);

    // provider/admin_label が無いと BlockPluginTrait で warning になる。
    $plugin_definition = [
      'provider' => 'site_notice',
      'admin_label' => 'Site Notice Bar',
    ];

    return new SiteNoticeBlock(
      $configuration,
      'site_notice_block',
      $plugin_definition,
      $config_factory,
      $time,
    );
  }

  /**
   * Provides a minimal valid configuration.
   */
  private function baseConfig(array $overrides = []): array {
    $base = [
      'message' => [
        'value' => 'Test message',
        'format' => 'basic_html',
      ],
      'link_url' => '',
      'start' => '',
      'end' => '',
      'background' => 'is-default',
      'closable' => FALSE,
      'storage_key_salt' => '',
    ];
    return $overrides + $base;
  }

  public function testBuildBeforeStartReturnsEmptyWithCacheUntilStart(): void {
    $now = 1_700_000_000;
    $start = $now + 3600; // 1 hour later.
    $config = $this->baseConfig([
      'start' => gmdate(DATE_ATOM, $start),
    ]);

    $block = $this->createBlock($config, $now);
    $build = $block->build();

    $this->assertArrayHasKey('message', $build);
    $this->assertArrayHasKey('#cache', $build);
    $this->assertArrayHasKey('max-age', $build['#cache']);
    $this->assertSame($start - $now, $build['#cache']['max-age']);
    $this->assertArrayHasKey('contexts', $build['#cache']);
    $this->assertContains('timezone', $build['#cache']['contexts']);
    $this->assertArrayNotHasKey('#theme', $build);
  }

  public function testBuildAfterEndReturnsEmptyWithPermanentCache(): void {
    $now = 1_700_000_000;
    $end = $now - 1; // Already ended.
    $config = $this->baseConfig([
      'end' => gmdate(DATE_ATOM, $end),
    ]);

    $block = $this->createBlock($config, $now);
    $build = $block->build();

    $this->assertArrayHasKey('#cache', $build);
    $this->assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
    $this->assertContains('timezone', $build['#cache']['contexts']);
    $this->assertArrayNotHasKey('#theme', $build);
  }

  public function testBuildWithinWindowRendersWithExpectedCacheAndSettings(): void {
    $now = 1_700_000_000;
    $start = $now - 10;
    $end = $now + 7200; // 2 hours later.
    $message = 'Important notice';
    $salt = 'abc';
    $config = $this->baseConfig([
      'message' => [
        'value' => $message,
        'format' => 'basic_html',
      ],
      'start' => gmdate(DATE_ATOM, $start),
      'end' => gmdate(DATE_ATOM, $end),
      'closable' => TRUE,
      'storage_key_salt' => $salt,
      'background' => 'is-warning',
      'link_url' => 'https://example.com',
    ]);

    $block = $this->createBlock($config, $now);
    $build = $block->build();

    // Renders with theme and attachments.
    $this->assertSame('site_notice', $build['#theme']);
    $this->assertTrue(in_array('site_notice/notice', $build['#attached']['library'], TRUE));
    $this->assertTrue($build['#attached']['drupalSettings']['siteNotice']['closable']);

    // Storage key is derived from message/start/end/salt (sha256, first 16 chars).
    $expected_key = 'site_notice_' . substr(hash('sha256',
      $message . '|' . gmdate(DATE_ATOM, $start) . '|' . gmdate(DATE_ATOM, $end) . '|' . $salt
    ), 0, 16);
    $this->assertSame($expected_key, $build['#attached']['drupalSettings']['siteNotice']['storageKey']);

    // Cache metadata: tags/contexts present, max-age until end.
    $this->assertContains('timezone', $build['#cache']['contexts']);
    $this->assertSame($end - $now, $build['#cache']['max-age']);
  }
}
