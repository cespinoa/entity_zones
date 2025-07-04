<?php
namespace Drupal\entity_zones\Service;

use Symfony\Component\Yaml\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;

class ZonedEntityRenderer {

  protected ModuleExtensionList $moduleHandler;
  protected FileSystemInterface $fileSystem;

  public function __construct(
    ModuleExtensionList $moduleHandler,
    FileSystemInterface $fileSystem
  ) {
    $this->moduleHandler = $moduleHandler;
    $this->fileSystem = $fileSystem;
  }

  public function getZonesFromFile(string $entity_type, ?string $bundle, string $view_mode): array {
    if ($bundle) {
      $filename = "$entity_type--$bundle--$view_mode.zones.yml";
    }
    else {
      $filename = "$entity_type--$view_mode.zones.yml";
    }

    $filename = str_replace('_', '-', $filename);

    

    // 1. Rutas a escanear
    $paths = [];

    // a) Interno: dentro del módulo
    $module_path = $this->moduleHandler->getPath('entity_zones');
    $paths[] = $module_path . '/zones';

    // b) Externo: public://entity_zones_templates/zones
    $config_path = \Drupal::config('entity_zones.settings')->get('template_directory') ?? 'public://entity_zones_templates';
    /** @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager */
    $streamWrapperManager = \Drupal::service('stream_wrapper_manager');
    $wrapper = $streamWrapperManager->getViaUri($config_path . '/zones');
    if ($wrapper && ($real = $wrapper->realpath())) {
      $paths[] = $real;
    }

    // 2. Buscar en todas las rutas (incluyendo subdirectorios)
    foreach ($paths as $base_dir) {
      if (!is_dir($base_dir)) {
        continue;
      }

      $files = $this->fileSystem->scanDirectory($base_dir, "/$filename$/", ['recurse' => TRUE]);
      if (!empty($files)) {
        
        /** @var \Drupal\Core\File\FileSystemInterface $file */
        $file = reset($files);
        $data = Yaml::parseFile($file->uri);
        return $data ?? [];
      }
    }

    return [];
  }

  public function prepareZones(array $fields, array $zones, array $zones_format = [], $entity): array {
    $output = [];

    // Atributos del wrapper
    if (isset($zones_format['__wrapper__'])) {
      $wrapper_format = $zones_format['__wrapper__'];
      $wrapper_attributes = [];

      foreach ($wrapper_format as $key => $value) {
        if ($key === 'class') {
          $wrapper_attributes['class'] = is_array($value) ? $value : explode(' ', $value);
        }
        elseif ($key !== '_tag') {
          $wrapper_attributes[$key] = $value;
        }
      }

      $output['#wrapper_attributes'] = $wrapper_attributes;

      if (isset($wrapper_format['_tag'])) {
        $output['#wrapper_tag'] = $wrapper_format['_tag'];
      }
    }


    foreach ($zones as $zone_name => $zone_fields) {
      $output[$zone_name] = [];

      // Añadir atributos si existen
      if (!empty($zones_format[$zone_name])) {
        $attributes = [];

        foreach ($zones_format[$zone_name] as $key => $value) {
          if ($key === 'class') {
            $attributes['class'] = is_array($value) ? $value : explode(' ', $value);
          }
          else {
            $attributes[$key] = $value;
          }
        }

        $output[$zone_name]['#attributes'] = $attributes;
      }

      // Añadir campos
      foreach ($zone_fields as $field_name => $config) {

        if (is_int($field_name)) {
          
          $field_name = $config;
        }
        if (isset($fields[$field_name])) {
          if (is_array($config)) {
            
            if (isset($config['tag']) || isset($config['link'])) {
              $output[$zone_name]['fields'][$field_name] = $this->buildTitleTemplate($config, $entity);
            }
            elseif (isset($config['template'])) {
              $output[$zone_name]['fields'][$field_name] = $this->buildInlineTemplate($config, $entity);
            }
            elseif (isset($config['linked'])) {
              $output[$zone_name]['fields'][$field_name] = $this->buildLinkedField($fields[$field_name], $config, $entity);
            }
            else {
              $output[$zone_name]['fields'][$field_name] = $fields[$field_name];
            }
          }
          else {
            $output[$zone_name]['fields'][$field_name] = $fields[$field_name];
          }
        }

      }
    }
    return $output;
  }

  /**
   * Construye un render array para envolver un campo con un enlace si está activado.
   *
   * @param array $field_render_array
   *   El render array original del campo (como llega en $variables['elements']).
   * @param array $options
   *   Configuración tomada del YAML (por ejemplo, ['linked' => true]).
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   La entidad para obtener la URL de destino.
   *
   * @return array
   *   Un render array, con el campo renderizado como enlace si corresponde.
   */
  protected function buildLinkedField(array $field_render_array, array $options,  $entity): array {
    $linked = $options['linked'] ?? false;

    // Si no se solicita el enlace o la URL no es enrutable, devolver el render normal.
    if (!$linked || !$entity->toUrl()->isRouted()) {
      return $field_render_array;
    }

    // Convertimos el render array a HTML con renderPlain para envolverlo.
    $field_markup = \Drupal::service('renderer')->renderPlain($field_render_array);

    return [
      '#type' => 'inline_template',
      '#template' => '<a href="{{ url }}">{{ field }}</a>',
      '#context' => [
        'field' => $field_markup,
        'url' => $entity->toUrl()->toString(),
      ],
    ];
  }


  protected function buildTitleTemplate(array $options, $entity): array {
    $tag = $options['tag'] ?? 'h2';
    $class = $options['class'] ?? '';
    $link = $options['link'] ?? false;

    // Atributos extra (excluyendo los conocidos)
    $known = ['tag', 'class', 'link'];
    $extra_attrs = array_diff_key($options, array_flip($known));

    // Construcción de atributos
    $attrs = [];
    if (!empty($class)) {
      $attrs[] = 'class="' . $class . '"';
    }

    foreach ($extra_attrs as $key => $value) {
      $attrs[] = $key . '="' . $value . '"';
    }

    $attr_string = $attrs ? ' ' . implode(' ', $attrs) : '';

    // Cuerpo del tag title
    if ($link && $entity->toUrl()->isRouted()) {
      $body = '<a href="{{ url }}">{{ title }}</a>';
    }
    else {
      $body = '{{ title }}';
    }

    return [
      '#type' => 'inline_template',
      '#template' => "<$tag$attr_string>$body</$tag>",
      '#context' => [
        'title' => $entity->label(),
        'url' => $entity->toUrl()->toString(),
      ],
    ];
  }

  protected function buildInlineTemplate(array $config,  $entity): array {
    $template = $config['template'] ?? '';
    $context = $config['context'] ?? [];

    foreach ($context as $key => $value) {
      if (is_string($value) && str_starts_with($value, '@')) {
        $property = substr($value, 1);

        // Soporte para métodos simples como getId(), getCreatedTime(), etc.
        $method = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $property)));
        if (method_exists($entity, $method)) {
          $context[$key] = $entity->$method();
          continue;
        }

        // Si el campo existe como field...
        if ($entity->hasField($property)) {
          $field = $entity->get($property);
          if (!$field->isEmpty()) {
            // Puedes adaptar esto si quieres más precisión según el tipo de campo.
            $context[$key] = $field->value ?? (string) $field;
            continue;
          }
        }

        // Casos especiales
        if ($property === 'label') {
          $context[$key] = $entity->label();
        }
        elseif ($property === 'url') {
          $context[$key] = $entity->toUrl()->toString();
        }
        else {
          // Si no lo resolvemos, lo dejamos como string literal
          $context[$key] = $value;
        }
      }
    }

    return [
      '#type' => 'inline_template',
      '#template' => $template,
      '#context' => $context,
    ];
  }



}
