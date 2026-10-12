<?php

namespace Drupal\massmail\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\massmail\MassMail;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the send mail form.
 */
class Mail extends ConfigFormBase {

  /**
   * The MassMail service.
   *
   * @var \Drupal\massmail\MassMail
   */
  protected MassMail $massMail;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->massMail = $container->get('massmail.manager');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'massmail_mail_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'massmail.mail',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Point to the module config if not set.
    if (!$this->massMail->isConfigured()) {
      $form['not_configured'] = [
        '#markup' => '<p>' . $this->t('You must first <a href=":url">configure the MassMail settings</a> (AWS environment variables, region, data directory and the private file system) to send email.', [
          ':url' => Url::fromRoute('massmail.settings')->toString(),
        ]) . '</p>',
      ];
      return $form;
    }

    $form_state->disableCache();
    $form = parent::buildForm($form, $form_state);

    $site = $this->config('system.site');
    $config = $this->config('massmail.mail');
    $tokens = $this->t('Tokens from "church" are available, e.g. [[column_name]]');

    $form['to'] = [
      '#default_value' => $config->get('to') ?: '',
      '#description' => $this->t('A valid email or identical token.') . ' ' . $tokens,
      '#placeholder' => 'recipient@email.com',
      '#type' => 'textfield',
      '#title' => $this->t('To address'),
      '#required' => TRUE,
    ];

    $form['from'] = [
      '#default_value' => $config->get('from') ?: $site->get('mail'),
      '#description' => $this->t('A valid email or identical token. Also receives tests.') . ' ' . $tokens,
      '#placeholder' => 'sender@email.com',
      '#type' => 'textfield',
      '#title' => $this->t('From address'),
      '#required' => TRUE,
    ];

    $form['addresses'] = [
      '#title' => $this->t('Notification addresses'),
      '#description' => $this->t('For return email notifications. Empty fields in this section use the From address. The bounce address must be verified in SES.'),
      '#type' => 'details',
      '#open' => FALSE,
    ];

    $form['addresses']['reply'] = [
      '#default_value' => $config->get('reply') ?: '',
      '#description' => $this->t('A valid email or identical token.') . ' ' . $tokens,
      '#placeholder' => 'reply@email.com',
      '#type' => 'textfield',
      '#title' => $this->t('Reply address'),
    ];

    $form['addresses']['bounce'] = [
      '#default_value' => $config->get('bounce') ?: '',
      '#description' => $this->t('A valid email or identical token.') . ' ' . $tokens,
      '#placeholder' => 'bounce@email.com',
      '#type' => 'textfield',
      '#title' => $this->t('Bounce address'),
    ];

    $form['addresses']['suppressionlist'] = [
      '#default_value' => $config->get('suppressionlist') ?: '',
      '#description' => $this->t('A valid email or identical token.') . ' ' . $tokens,
      '#placeholder' => 'unsubscribe@email.com',
      '#type' => 'textfield',
      '#title' => $this->t('Unsubscribe address'),
    ];

    $form['subject'] = [
      '#default_value' => $config->get('subject') ?: '',
      '#description' => $tokens,
      '#placeholder' => $this->t('Regarding Your MassTimes Account'),
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#required' => TRUE,
    ];

    $form['body'] = [
      '#default_value' => $config->get('body') ?: '',
      '#description' => $this->t('Service data will be auto-added below the body intro.') . ' ' . $tokens,
      '#placeholder' => $this->t('Greetings! This is a friendly message from MassTimes regarding your account information...'),
      '#type' => 'textarea',
      '#title' => $this->t('Body intro'),
      '#required' => TRUE,
    ];

    $sources = [
      'source_fid_0' => $this->t('Church data (csv)'),
      'source_fid_1' => $this->t('Services data (csv)'),
    ];
    $weight = 1;
    foreach ($sources as $key => $title) {
      $fid = $config->get($key);
      $form[$key] = [
        '#title' => $title,
        '#type' => 'managed_file',
        '#upload_location' => $this->massMail->dataUri(),
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'csv'],
        ],
        '#default_value' => $fid ? [$fid] : NULL,
        '#required' => TRUE,
        '#weight' => $weight++,
      ];
    }

    $form['actions']['#type'] = 'actions';

    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#submit' => ['::saveSubmit'],
      '#weight' => 10,
    ];

    $form['actions']['send_test'] = [
      '#attributes' => [
        'class' => ['btn-warning'],
        'onclick' => $this->confirmScript($this->t('Send the first and last emails to the From address as a test?')),
      ],
      '#type' => 'submit',
      '#value' => $this->t('Save & Test'),
      '#submit' => ['::testSubmit'],
      '#weight' => 11,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save & Send'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['btn-danger'],
        'onclick' => $this->confirmScript($this->t('CONFIRM LIVE SENDING: this emails every church in the data file. Are you sure?')),
      ],
      '#weight' => 12,
    ];

    return $form;
  }

  /**
   * Inline click handler: cancels the submit unless confirmed.
   */
  protected function confirmScript(string $message): string {
    return 'if (!window.confirm(' . json_encode($message, JSON_HEX_APOS | JSON_HEX_QUOT) . ')) { return false; } this.classList.add("disabled");';
  }

  public function saveSubmit(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('massmail.mail');

    $config
      ->set('to', $values['to'])
      ->set('from', $values['from'])
      ->set('reply', $values['reply'])
      ->set('bounce', $values['bounce'])
      ->set('suppressionlist', $values['suppressionlist'])
      ->set('subject', $values['subject'])
      ->set('body', $values['body']);

    $storage = $this->entityTypeManager->getStorage('file');
    foreach (['source_fid_0', 'source_fid_1'] as $key) {
      if (!empty($values[$key][0])) {
        $fid = $values[$key][0];
        // Keep the upload; temporary files are removed by cron.
        if ($file = $storage->load($fid)) {
          $file->setPermanent();
          $file->save();
        }
        $config->set($key, $fid);
      }
    }

    $config->save();
    $this->messenger()->addMessage($this->t('Settings saved.'));
  }

  /**
   * Saves, then sends the test emails.
   */
  public function testSubmit(array &$form, FormStateInterface $form_state) {
    $this->saveSubmit($form, $form_state);
    $this->massMail->startSend('test');
    $this->messenger()->addMessage($this->t('Testing MassMail email queue.'));
    $form_state->setRedirect('massmail.status');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->saveSubmit($form, $form_state);
    $this->massMail->startSend('live');
    $this->messenger()->addMessage($this->t('Sending MassMail email queue.'));
    $form_state->setRedirect('massmail.status');
  }

}
