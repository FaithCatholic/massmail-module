<?php

namespace Drupal\massmail\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\massmail\MassMail;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class Status extends ControllerBase {

  public function __construct(
    protected MassMail $massMail,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('massmail.manager'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * Status page.
   * @return array
   */
  public function page() {
    $build = ['#cache' => ['max-age' => 0]];
    $line = $this->massMail->lastLogLine();

    if ($line === NULL) {
      $build['title'] = ['#markup' => '<h4>' . $this->t('Send queue is empty.') . '</h4>'];
      $build['text'] = ['#markup' => '<p>' . $this->t('There is no log of a previous send.') . '</p>'];
      return $build;
    }

    if ($line === MassMail::COMPLETE) {
      $build['title'] = ['#markup' => '<h4>' . $this->t('Sending complete!') . '</h4>'];
    }
    elseif (str_starts_with($line, MassMail::ERROR)) {
      $build['title'] = ['#markup' => '<h4>' . $this->t('Sending failed.') . '</h4>'];
      $build['text'] = ['#plain_text' => $line, '#prefix' => '<p>', '#suffix' => '</p>'];
    }
    else {
      $build['title'] = ['#markup' => '<h4>' . $this->t('Sending...') . '</h4>'];
      $build['text'] = ['#markup' => '<p>' . $this->t('This may take several minutes to complete. Refresh this page to check for completion.') . '</p>'];
      $build['step'] = ['#markup' => '<p>' . $this->t('Last step: @line', ['@line' => $line]) . '</p>'];
    }

    $build['log'] = Link::fromTextAndUrl(
      $this->t('Download the log'),
      Url::fromUri($this->fileUrlGenerator->generateAbsoluteString($this->massMail->logUri()))
    )->toRenderable();

    return $build;
  }

}
