<?php
namespace Drupal\entity_zones\Service;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;

class ZoneDefinitionLocator {

  protected FileSystemInterface $fileSystem;
  protected StreamWrapperManagerInterface $streamWrapperManager;
  protected ExtensionPathResolver $pathResolver;

  public function __construct(
    FileSystemInterface $fileSystem,
    StreamWrapperManagerInterface $streamWrapperManager,
    ExtensionPathResolver $pathResolver
  ) {
    $this->fileSystem = $fileSystem;
    $this->streamWrapperManager = $streamWrapperManager;
    $this->pathResolver = $pathResolver;
  }

  /**
   * Busca todos los archivos *.zones.yml válidos en ubicaciones conocidas.
   *
   * @return array<string> Array de rutas completas a archivos YAML.
   */
  public function findAllZoneDefinitions(): array {
    $pattern = '/\.zones\.yml$/';
    $found = [];

    // 1. Buscar en el módulo.
    $module_dir = $this->pathResolver->getPath('module', 'entity_zones') . '/zones';
    if (is_dir($module_dir)) {
      $found += $this->scan($module_dir, $pattern);
    }

    // 2. Buscar en el directorio de configuración pública.
    $config_path = \Drupal::config('entity_zones.settings')->get('template_directory') ?? 'public://entity_zones_templates';
    $wrapper = $this->streamWrapperManager->getViaUri($config_path . '/zones');
    $real_path = $wrapper ? $wrapper->realpath() : NULL;

    if ($real_path && is_dir($real_path)) {
      $found += $this->scan($real_path, $pattern);
    }


    return array_values($found);
  }

  /**
   * Encuentra todos los templates zoneds en las ubicaciones definidas.
   *
   * @return array<int, array{theme_hook: string, template: string, path: string}>
   */
  public function findAllZonedTemplates(): array {
    $results = [];
    $template_dirs = [];

    // 1. Interno
    $module_path = $this->pathResolver->getPath('module', 'entity_zones');
    $template_dirs[] = $module_path . '/templates';

    // 2. Externo
    $config_path = \Drupal::config('entity_zones.settings')->get('template_directory') ?? 'public://entity_zones_templates';
    $wrapper = $this->streamWrapperManager->getViaUri($config_path . '/templates');
    $external_path = $wrapper ? $wrapper->realpath() : NULL;

    if ($external_path) {
      $template_dirs[] = $external_path;
    }

    foreach ($template_dirs as $dir) {
      if (!is_dir($dir)) {
        continue;
      }

      $files = $this->fileSystem->scanDirectory($dir, '/\.html\.twig$/', ['recurse' => TRUE]);

      foreach ($files as $file) {
        $basename = $file->filename;
        $parts = explode('--', $basename);
        
        if (count($parts) === 3) {
          [$entity_type, $view_mode, $suffix] = $parts;
          $bundle = NULL;
        }
        elseif (count($parts) === 4) {
          [$entity_type, $bundle, $view_mode, $suffix] = $parts;
        }
        if($entity_type && $view_mode && $suffix){
          array_pop($parts);
          $template = implode('--', $parts);
          $theme_hook = str_replace('-', '_', $template);
          $entity_type = str_replace('-', '_', $entity_type);

          $path = dirname($file->uri);
          if(str_contains($path, 'entity_zones_templates/templates')) {
            $component = explode('entity_zones_templates/templates', $path)[1];
            $path = '@entity_zones_ext' . $component;
          }
          $results[] = [
            'theme_hook' => $theme_hook . '__zoned',
            'template' => $template . '--zoned',
            'path' => $path,
            'entity_type' => $entity_type,
            'view_mode' => $view_mode,
          ];
        }
      }
    }

    return $results;
  }


  /**
   * Escanea un directorio y devuelve los archivos que coincidan con el patrón.
   */
  protected function scan(string $directory, string $pattern): array {
    return $this->fileSystem->scanDirectory($directory, $pattern, ['recurse' => TRUE]);
  }
}
