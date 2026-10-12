<?php

namespace Drupal\massmail\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the settings form.
 */
class Settings extends ConfigFormBase {

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
  public function buildForm(array $form, FormStateInterface $form_state, ?Request $request = NULL) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('massmail.settings');

    $form['aws'] = array(
      '#title' => t('AWS'),
      '#type' => 'fieldset',
    );

    // Keys come from the environment, never from config.
    $credentials = massmail_aws_credentials();

    $form['aws']['aws_access'] = array(
      '#markup' => $credentials['key'] ? t('Set') : '<strong>' . t('Not set') . '</strong>',
      '#title' => 'Access key',
      '#type' => 'item',
      '#description' => t('From the MASSMAIL_AWS_ACCESS_KEY_ID environment variable.'),
    );

    $form['aws']['aws_secret'] = array(
      '#markup' => $credentials['secret'] ? t('Set') : '<strong>' . t('Not set') . '</strong>',
      '#title' => 'Secret key',
      '#type' => 'item',
      '#description' => t('From the MASSMAIL_AWS_SECRET_ACCESS_KEY environment variable.'),
    );

    $form['aws']['aws_region'] = array(
      '#default_value' => $config->get('aws_region') ? $config->get('aws_region') : 'us-east-1',
      '#options' => array(
        'us-east-1' => 'US East (N. Virginia)',
        'us-east-2' => 'US East (Ohio)',
        'us-west-1' => 'US West (N. California)',
        'us-west-2' => 'US West (Oregon)',
      ),
      '#required' => TRUE,
      '#title' => 'Region',
      '#type' => 'select',
    );

    $form['data'] = array(
      '#title' => t('Data'),
      '#type' => 'fieldset',
    );

    $form['data']['data_dir'] = array(
      '#default_value' => $config->get('data_dir') ? $config->get('data_dir') : '',
      '#description' => t('A path relative to the private file directory. (No leading or trailing slashes.)'),
      '#required' => TRUE,
      '#title' => 'Data directory',
      '#type' => 'textfield',
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $this->config('massmail.settings')
      ->set('aws_region', $values['aws_region'])
      ->set('data_dir', $values['data_dir'])
      ->save();

    parent::submitForm($form, $form_state);
  }

}
