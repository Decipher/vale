<?php

declare(strict_types=1);

namespace Drupal\vale\Canvas;

use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropShape\PropShape;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Reads the prose in a Canvas component tree.
 *
 * This is the only vale class that uses Canvas. Several of the Canvas APIs it
 * calls are marked internal, so a change in Canvas should only need a change
 * here. The service is defined only when Canvas is installed.
 *
 * A prop holds prose when Canvas says its shape is plain or rich prose. That
 * leaves out lists of choices, links and other structured values. Block
 * components have no prop shapes, so they are left out too.
 */
final class ComponentTreeProse {

  /**
   * Lists the prose in a component tree field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The component tree field.
   * @param string[] $skipped
   *   Prop names to leave out.
   *
   * @return list<array{delta: int, uuid: string, prop: string, component: string, text: string, format: string|null}>
   *   One entry per piece of text. The format is NULL for plain text. A prop
   *   that holds a list of values is named with each value's index, such as
   *   "items.1".
   */
  public function values(FieldItemListInterface $items, array $skipped): array {
    $values = [];
    foreach ($items as $delta => $item) {
      // FieldLinter only passes a component tree field here.
      assert($item instanceof ComponentTreeItem);
      try {
        $component = $item->getComponent();
        $source = $component?->getComponentSource();
        if (!$component || !$source instanceof JsonSchemaPropsComponentSourceBase) {
          continue;
        }
        $uuid = $item->getUuid();
        // This turns stored values back into their full form, the same way
        // Canvas does to render them.
        $inputs = $source->getExplicitInput($uuid, $item)['source'];
      }
      catch (\Exception) {
        // Canvas reports a broken component instance itself.
        continue;
      }
      $label = (string) ($item->getLabel() ?: $component->label());
      foreach ($inputs as $prop => $input) {
        $prop = (string) $prop;
        if (in_array($prop, $skipped, TRUE) || !$this->isProse($source, $prop)) {
          continue;
        }
        foreach ($this->texts($input['value'] ?? NULL) as $index => [$text, $format]) {
          $values[] = [
            'delta' => $delta,
            'uuid' => $uuid,
            'prop' => $index === '' ? $prop : $prop . '.' . $index,
            'component' => $label,
            'text' => $text,
            'format' => $format,
          ];
        }
      }
    }
    return $values;
  }

  /**
   * Says whether a prop of a component version holds prose.
   *
   * @param \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase $source
   *   The component source, at the version the instance uses.
   * @param string $prop
   *   The prop name.
   *
   * @return bool
   *   TRUE for plain or rich prose that the author typed.
   */
  private function isProse(JsonSchemaPropsComponentSourceBase $source, string $prop): bool {
    // Canvas keeps the text shape of each prop on the component version. A
    // prop filled from a reference, such as an image, holds no prose.
    if (!$source->isExplicitInputTranslatable($prop)) {
      return FALSE;
    }
    $shape = $source->getConfiguration()['prop_field_definitions'][$prop]['derived_schema_metadata']['string_shape'] ?? NULL;
    return is_array($shape) && PropShape::isPlainOrRichProse(['type' => 'string'] + $shape);
  }

  /**
   * Gives the pieces of text in a static prop value.
   *
   * @param mixed $value
   *   A string, a rich text value with "value" and "format", or a list of
   *   either.
   *
   * @return array<int|string, array{string, string|null}>
   *   The text and its format, keyed by list index, or by an empty string
   *   for a single value.
   */
  private function texts(mixed $value): array {
    $one = static function (mixed $value): ?array {
      if (is_string($value)) {
        return [$value, NULL];
      }
      if (is_array($value) && is_string($value['value'] ?? NULL)) {
        return [$value['value'], is_string($value['format'] ?? NULL) ? $value['format'] : NULL];
      }
      return NULL;
    };
    $single = $one($value);
    if ($single !== NULL) {
      return trim($single[0]) === '' ? [] : ['' => $single];
    }
    $texts = [];
    foreach (is_array($value) && array_is_list($value) ? $value : [] as $index => $item) {
      $text = $one($item);
      if ($text !== NULL && trim($text[0]) !== '') {
        $texts[$index] = $text;
      }
    }
    return $texts;
  }

}
