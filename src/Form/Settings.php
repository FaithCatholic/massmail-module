<?php

namespace Drupal\massmail\Form;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\massmail\MassMail;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the settings form.
 */
class Settings extends ConfigFormBase {

  /**
   * The MassMail service.
   *
   * @var \Drupal\massmail\MassMail
   */
  protected MassMail $massMail;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->massMail = $container->get('massmail.manager');
    $instance->fileSystem = $container->get('file_system');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'massmail_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'massmail.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('massmail.settings');

    $form['aws'] = [
      '#title' => $this->t('AWS'),
      '#type' => 'fieldset',
    ];

    // Keys come from the environment, never from config.
    $credentials = $this->massMail->credentials();

    $form['aws']['aws_access'] = [
      '#markup' => $credentials['key'] ? $this->t('Set') : '<strong>' . $this->t('Not set') . '</strong>',
      '#title' => $this->t('Access key'),
      '#type' => 'item',
      '#description' => $this->t('From the MASSMAIL_AWS_ACCESS_KEY_ID environment variable.'),
    ];

    $form['aws']['aws_secret'] = [
      '#markup' => $credentials['secret'] ? $this->t('Set') : '<strong>' . $this->t('Not set') . '</strong>',
      '#title' => $this->t('Secret key'),
      '#type' => 'item',
      '#description' => $this->t('From the MASSMAIL_AWS_SECRET_ACCESS_KEY environment variable.'),
    ];

    $form['aws']['aws_region'] = [
      '#default_value' => $config->get('aws_region') ?: 'us-east-1',
      '#options' => [
        'us-east-1' => 'US East (N. Virginia)',
        'us-east-2' => 'US East (Ohio)',
        'us-west-1' => 'US West (N. California)',
        'us-west-2' => 'US West (Oregon)',
      ],
      '#required' => TRUE,
      '#title' => $this->t('Region'),
      '#type' => 'select',
    ];

    $form['data'] = [
      '#title' => $this->t('Data'),
      '#type' => 'fieldset',
    ];

    $form['data']['private'] = [
      '#markup' => $this->fileSystem->realpath('private://') ? $this->t('Configured') : '<strong>' . $this->t('Not configured') . '</strong>',
      '#title' => $this->t('Private file system'),
      '#type' => 'item',
      '#description' => $this->t('Source data and logs are only stored in private files. Set $settings[\'file_private_path\'] in settings.php.'),
    ];

    $form['data']['data_dir'] = [
      '#default_value' => $config->get('data_dir') ?: 'massmail',
      '#description' => $this->t('A path relative to the private file directory, e.g. massmail.'),
      '#required' => TRUE,
      '#title' => $this->t('Data directory'),
      '#type' => 'textfield',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $data_dir = trim($form_state->getValue('data_dir'), " /");
    if ($data_dir === '' || str_contains($data_dir, '..')) {
      $form_state->setErrorByName('data_dir', $this->t('Enter a directory name inside the private file directory.'));
    }
    $form_state->setValue('data_dir', $data_dir);
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('massmail.settings')
      ->set('aws_region', $form_state->getValue('aws_region'))
      ->set('data_dir', $form_state->getValue('data_dir'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
