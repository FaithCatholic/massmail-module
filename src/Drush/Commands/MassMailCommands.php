<?php

namespace Drupal\massmail\Drush\Commands;

use Drupal\massmail\MassMail;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MassMail Drush commands.
 */
final class MassMailCommands extends DrushCommands {

  public function __construct(
    protected MassMail $massMail,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new static($container->get('massmail.manager'));
  }

  /**
   * Sends the MassMail emails configured at /massmail/send.
   */
  #[CLI\Command(name: 'massmail:send')]
  #[CLI\Argument(name: 'mode', description: "'test' sends the first and last emails to the From address; 'live' sends all of them.")]
  #[CLI\Usage(name: 'drush massmail:send test', description: 'Send the two test emails.')]
  public function send(string $mode = 'test'): int {
    if (!in_array($mode, ['test', 'live'], TRUE)) {
      $this->logger()->error("Mode must be 'test' or 'live'.");
      return self::EXIT_FAILURE;
    }
    if (!$this->massMail->isConfigured()) {
      $this->logger()->error('MassMail is not configured: check the AWS environment variables, the settings page and the private file system.');
      return self::EXIT_FAILURE;
    }

    try {
      $this->massMail->send($mode, fn (string $line) => $this->output()->writeln($line));
    }
    catch (\Throwable $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    return self::EXIT_SUCCESS;
  }

}
