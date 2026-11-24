<?php

declare(strict_types=1);

namespace Drupal\vale\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\vale\ValeEnvironment;
use Drupal\vale\ValePackages;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the site's Vale configuration to Vale's browser build.
 *
 * The browser build reads nothing from the server by itself. These routes
 * give it the .vale.ini for a language, the styles, and a language's model,
 * and nothing else from the server.
 */
final readonly class BrowserConfigController implements ContainerInjectionInterface {

  /**
   * How long a browser keeps a zip asked for by fingerprint, in seconds.
   */
  private const int KEPT = 31536000;

  /**
   * Constructs a BrowserConfigController.
   *
   * @param \Drupal\vale\ValeEnvironment $valeEnvironment
   *   The Vale environment.
   * @param \Drupal\vale\ValePackages $valePackages
   *   The packer for the styles.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    private ValeEnvironment $valeEnvironment,
    private ValePackages $valePackages,
    private LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('vale.environment'),
      $container->get('vale.packages'),
      $container->get('language_manager'),
    );
  }

  /**
   * Gives the .vale.ini the browser build reads a language with.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $langcode
   *   The langcode of the text, such as "fr", "en" or "und".
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The configuration, as plain text.
   */
  public function config(Request $request, string $langcode): Response {
    $ini = $this->languageManager->getLanguage($langcode) === NULL ? NULL : $this->valeEnvironment->browserConfig($langcode);
    if ($ini === NULL) {
      throw new NotFoundHttpException();
    }
    return $this->respond($request, $ini, 'text/plain; charset=utf-8', 'vale-' . $langcode . '.ini', 'inline');
  }

  /**
   * Gives the styles as a zip.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The zip.
   */
  public function styles(Request $request): Response {
    $zip = $this->valePackages->styles();
    if ($zip === NULL) {
      throw new NotFoundHttpException();
    }
    return $this->respond($request, $zip, 'application/zip', 'vale-styles.zip', 'attachment', TRUE);
  }

  /**
   * Gives one language's model and spelling dictionary as a zip.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $langcode
   *   The langcode, such as "fr".
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The zip.
   */
  public function models(Request $request, string $langcode): Response {
    $zip = $this->valePackages->models($langcode);
    if ($zip === NULL) {
      throw new NotFoundHttpException();
    }
    return $this->respond($request, $zip, 'application/zip', 'vale-models-' . $langcode . '.zip', 'attachment', TRUE);
  }

  /**
   * Makes a response a browser checks again before each use.
   *
   * The ETag lets a browser keep what it has until the styles change. The
   * response is private, as it needs a permission.
   *
   * A zip asked for with the fingerprint of the styles in "v" is the zip of
   * those styles for good, as changed styles have another fingerprint. So a
   * browser keeps that one without asking again.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $content
   *   The content.
   * @param string $type
   *   The content type.
   * @param string $filename
   *   The file name to save it as.
   * @param string $disposition
   *   Whether a browser saves it or shows it.
   * @param bool $fingerprinted
   *   Whether the content changes only when the fingerprint does.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response, or a 304 when the browser has it already.
   */
  private function respond(Request $request, string $content, string $type, string $filename, string $disposition = 'attachment', bool $fingerprinted = FALSE): Response {
    $response = new Response($content, 200, [
      'Content-Type' => $type,
      'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $filename),
    ]);
    $response->setEtag(hash('sha256', $content));
    $response->setPrivate();
    if ($fingerprinted && $request->query->get('v') === $this->valeEnvironment->fingerprint()) {
      $response->setMaxAge(self::KEPT);
      $response->setImmutable();
    }
    else {
      $response->setMaxAge(0);
      $response->headers->addCacheControlDirective('must-revalidate');
    }
    $response->isNotModified($request);
    return $response;
  }

}
