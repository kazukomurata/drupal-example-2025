<?php

namespace Drupal\site_notice\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Site Notice block.
 *
 * @Block(
 *   id = "site_notice_block",
 *   admin_label = @Translation("Site Notice Bar")
 * )
 */
class SiteNoticeBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a SiteNoticeBlock object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'message' => '',
      'link_url' => '',
      'start' => '',
      'end' => '',
      'background' => 'is-default',
      'closable' => FALSE,
      'storage_key_salt' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $config = $this->getConfiguration();

    $form['message'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Notice message'),
      '#format' => $config['message']['format'] ?? 'basic_html',
      '#default_value' => $config['message']['value'] ?? '',
      '#description' => $this->t('Short text recommended. Decoration possible with basic_html.'),
    ];

    $form['link_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Link URL (optional)'),
      '#default_value' => $config['link_url'] ?? '',
    ];

    $form['schedule'] = [
      '#type' => 'details',
      '#title' => $this->t('Schedule'),
      '#open' => TRUE,
    ];

    $form['schedule']['start'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Start datetime'),
      '#default_value' => $config['start'] ? DrupalDateTime::createFromTimestamp(strtotime($config['start'])) : NULL,
    ];

    $form['schedule']['end'] = [
      '#type' => 'datetime',
      '#title' => $this->t('End datetime'),
      '#default_value' => $config['end'] ? DrupalDateTime::createFromTimestamp(strtotime($config['end'])) : NULL,
      '#description' => $this->t('No time limit if not set'),
    ];

    $form['appearance'] = [
      '#type' => 'details',
      '#title' => $this->t('Appearance'),
      '#open' => TRUE,
    ];
    $form['appearance']['background'] = [
      '#type' => 'select',
      '#title' => $this->t('Background color'),
      '#options' => [
        'is-default' => $this->t('Default'),
        'is-info' => $this->t('Info'),
        'is-success' => $this->t('Success'),
        'is-warning' => $this->t('Warning'),
        'is-danger' => $this->t('Danger'),
      ],
      '#default_value' => $config['background'] ?? 'is-default',
    ];
    $form['appearance']['closable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show close button'),
      '#default_value' => (bool) $config['closable'],
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
      '#open' => FALSE,
    ];
    $form['advanced']['storage_key_salt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Storage key salt'),
      '#default_value' => $config['storage_key_salt'] ?? '',
      '#description' => $this->t('For avoiding localStorage key collisions. Works even if left empty.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $this->setConfigurationValue('message', $values['message']);
    $this->setConfigurationValue('link_url', trim((string) $values['link_url']));
    $this->setConfigurationValue('start', $values['schedule']['start'] ? $values['schedule']['start']->format(DATE_ATOM) : '');
    $this->setConfigurationValue('end', $values['schedule']['end'] ? $values['schedule']['end']->format(DATE_ATOM) : '');
    $this->setConfigurationValue('background', $values['appearance']['background']);
    $this->setConfigurationValue('closable', (bool) $values['appearance']['closable']);
    $this->setConfigurationValue('storage_key_salt', trim((string) $values['advanced']['storage_key_salt']));
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Use block configuration for settings.
    $config = $this->getConfiguration();

    $now = $this->time->getRequestTime();
    $start_iso = $config['start'];
    $end_iso = $config['end'];

    $start_ts = $start_iso ? strtotime($start_iso) : NULL;
    $end_ts = $end_iso ? strtotime($end_iso) : NULL;

    // まだ開始前.
    if ($start_ts && $now < $start_ts) {
      // キャッシュを「開始時刻まで」持たせる.
      $max_age = max(0, $start_ts - $now);
      $metadata = new CacheableMetadata();
      $metadata->setCacheMaxAge($max_age);
      $metadata->addCacheTags(['config:site_notice.settings']);
      $metadata->addCacheContexts(['timezone']);
      $build = [];
      $metadata->applyTo($build);
      return $build;
    }

    // 終了済み.
    if ($end_ts && $now > $end_ts) {
      // 次の設定更新までキャッシュ（= 非表示).
      return [
        '#cache' => [
          'tags' => ['config:site_notice.settings'],
          'contexts' => ['timezone'],
          'max-age' => Cache::PERMANENT,
        ],
      ];
    }

    $message = $config['message']['value'] ?: '';
    $format = $config['message']['format'] ?: '';
    $link = trim((string) $config['link_url']);
    $background = $config['background'] ?: 'is-default';
    $closable = (bool) $config['closable'];

    $render = [
      '#theme' => 'site_notice',
      '#message' => [
        '#type' => 'processed_text',
        '#text' => $message,
        '#format' => $format,
      ],
      '#link' => $link,
      '#background' => $background,
      '#closable' => $closable,
      '#attached' => [
        'library' => [
          'site_notice/notice',
        ],
        'drupalSettings' => [
          'siteNotice' => [
            'closable' => $closable,
            // メッセージと期間からキーを作る。テキスト変更で既読がリセットされる.
            'storageKey' => 'site_notice_' . substr(hash('sha256',
                ($message ?: '') . '|' . ($start_iso ?: '') . '|' . ($end_iso ?: '') . '|' . ($config['storage_key_salt'] ?: '')
              ), 0, 16),
          ],
        ],
      ],
      '#cache' => [
        'tags' => ['config:site_notice.settings'],
        'contexts' => ['timezone'],
        // 表示期間中は「終了まで」をmax-ageにして自動更新.
        'max-age' => ($end_ts ? max(0, $end_ts - $now) : Cache::PERMANENT),
      ],
    ];

    return $render;
  }

}
