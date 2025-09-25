<?php

namespace Drupal\Tests\site_notice\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the Site Notice block logic at the Kernel level.
 */
class SiteNoticeBlockKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'filter',
    'site_notice',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'filter']);

    if (!FilterFormat::load('plain_text')) {
      FilterFormat::create([
        'format' => 'plain_text',
        'name' => 'Plain text',
        'filters' => [],
      ])->save();
    }
  }

  /**
   * Ensures the block build contains expected values during the active period.
   */
  public function testBuildWithinSchedule(): void {
    $configuration = [
      'message' => [
        'value' => 'Kernel test message',
        'format' => 'plain_text',
      ],
      'link_url' => 'https://example.com',
      'start' => '2000-01-01T00:00:00+00:00',
      'end' => '2099-01-01T00:00:00+00:00',
      'background' => 'is-info',
      'closable' => TRUE,
      'storage_key_salt' => 'kernel',
    ];

    $block = $this->container->get('plugin.manager.block')->createInstance('site_notice_block', $configuration);
    $build = $block->build();

    $this->assertSame('site_notice', $build['#theme']);
    $this->assertArrayHasKey('#message', $build);
    $this->assertSame('is-info', $build['#background']);
    $this->assertSame('https://example.com', $build['#link']);
    $this->assertTrue($build['#closable']);
    $this->assertContains('timezone', $build['#cache']['contexts']);

    $settings = $build['#attached']['drupalSettings']['siteNotice'];
    $this->assertTrue($settings['closable']);
    $this->assertStringStartsWith('site_notice_', $settings['storageKey']);
  }

  /**
   * Ensures the block respects the start schedule and caches until activation.
   */
  public function testBuildBeforeSchedule(): void {
    $time = $this->container->get('datetime.time')->getRequestTime();
    $start = gmdate(DATE_ATOM, $time + 3600);

    $configuration = [
      'message' => [
        'value' => 'Future notice',
        'format' => 'plain_text',
      ],
      'start' => $start,
      'end' => '',
    ];

    $block = $this->container->get('plugin.manager.block')->createInstance('site_notice_block', $configuration);
    $build = $block->build();

    $this->assertArrayHasKey('#cache', $build);
    $this->assertArrayNotHasKey('#theme', $build);
    $this->assertSame(3600, $build['#cache']['max-age']);
    $this->assertContains('timezone', $build['#cache']['contexts']);
  }

}
