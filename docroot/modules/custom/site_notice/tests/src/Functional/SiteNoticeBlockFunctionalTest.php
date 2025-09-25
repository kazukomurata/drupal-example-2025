<?php

namespace Drupal\Tests\site_notice\Functional;

use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests rendering the Site Notice block on a real page.
 */
class SiteNoticeBlockFunctionalTest extends BrowserTestBase {

  use BlockCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'system',
    'user',
    'filter',
    'site_notice',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!FilterFormat::load('plain_text')) {
      FilterFormat::create([
        'format' => 'plain_text',
        'name' => 'Plain text',
        'filters' => [],
      ])->save();
    }

    $plugin = $this->container->get('plugin.manager.block')->createInstance('site_notice_block');
    $settings = $plugin->defaultConfiguration();
    $settings['id'] = 'site_notice_block';
    $settings['provider'] = 'site_notice';
    $settings['label'] = 'Site Notice Bar';
    $settings['label_display'] = FALSE;
    $settings['message'] = [
      'value' => 'Functional test notice',
      'format' => 'plain_text',
    ];
    $settings['link_url'] = 'https://example.com/info';
    $settings['start'] = '2000-01-01T00:00:00+00:00';
    $settings['end'] = '2099-01-01T00:00:00+00:00';
    $settings['background'] = 'is-success';
    $settings['closable'] = TRUE;
    $settings['storage_key_salt'] = 'functional';
    $this->placeBlock('site_notice_block', $settings);
  }

  /**
   * Ensures the notice is visible with its configured data.
   */
  public function testNoticeIsRendered(): void {
    $this->drupalGet('<front>');
    $session = $this->assertSession();
    $session->statusCodeEquals(200);
    $session->pageTextContains('Functional test notice');
    $session->responseContains('https://example.com/info');
    $session->responseContains('&times;');
    $session->responseContains('site_notice');
  }

}
