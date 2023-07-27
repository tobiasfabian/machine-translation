<?php

namespace Tobiaswolf\MachineTranslation;

use Kirby\Cms\Block;
use Kirby\Cms\Blocks;
use Kirby\Cms\Fieldsets;
use Kirby\Cms\Layout;
use Kirby\Cms\LayoutColumn;
use Kirby\Cms\Layouts;
use Kirby\Cms\StructureObject;
use Kirby\Content\Content;
use Kirby\Content\Field;
use Kirby\Exception\Exception;
use Kirby\Http\Remote;
use Kirby\Toolkit\Str;

class Translate
{
	private const DEEPL_API_DOMAIN = 'api.deepl.com';
	private const DEEPL_API_FREE_DOMAIN = 'api-free.deepl.com';

	/**
	 * Translates the given text field to the target language.
	 *
	 * @param Field $field The field to be translated.
	 * @param string $targetLang The target language code (e.g., 'en', 'fr', 'es').
	 *
	 * @return Field The translated field.
	 */
	static function translateTextField(Field $field, string $targetLang): Field
	{
		if ($field->value === null) {
			return $field;
		}

		$sourceLang = $field->parent()->kirby()->language()->code();

		$translatedText = Translate::translate([$field->value], $targetLang, $sourceLang);
		$field->value = $translatedText[0]['text'];
		return $field;
	}

	/**
	 * Translates the layout field to the target language.
	 *
	 * @param Field $field The field to be translated.
	 * @param string $targetLang The target language code (e.g., 'en', 'fr', 'es').
	 * @param array $blueprintField The blueprint settings for the field. It must contain 'type' and can have 'translate'.
	 *
	 * @return Field The translated field.
	 */
	static function translateLayoutField(Field $field, string $targetLang, array $blueprintField): Field
	{
		/*** @var Layouts $layouts */
		$layouts = $field->toLayouts();
		$layouts->map(function (Layout $layout) use ($targetLang, $blueprintField): Layout
		{
			$layoutColumns = $layout->columns()->map(function (LayoutColumn $layoutColumn) use ($targetLang, $blueprintField): LayoutColumn
			{
				$blocks = $layoutColumn->blocks(true);
				$blocks = static::translateBlocks($blocks, $targetLang, $blueprintField);
				$layoutColumn = LayoutColumn::factory([
					'blocks' => $blocks->toArray(),
					'width' => $layoutColumn->width(),
				]);
				return $layoutColumn;
			});
			$layout = new Layout([
				'columns' => $layoutColumns->toArray(),
				'attrs' => $layout->attrs()->toArray(),
			]);
			return $layout;
		});
		$layouts = Layouts::factory($layouts->toArray());
		$field->value = $layouts->toArray();
		return $field;
	}

	/**
	 * Translates the blocks field to the target language.
	 *
	 * @param Field $field The field to be translated.
	 * @param string $targetLang The target language code (e.g., 'en', 'fr', 'es').
	 * @param array $blueprintField The blueprint settings for the field. It must contain 'type' and can have 'translate'.
	 *
	 * @return Field The translated field.
	 */
	static function translateBlocksField(Field $field, string $targetLang, array $blueprintField): Field
	{
		$blocks = Blocks::parse($field->value());
		$blocks = Blocks::factory($blocks, [
			'parent' => $field->parent(),
			'field'	=> $field,
		]);
		$blocks = static::translateBlocks($blocks, $targetLang, $blueprintField);
		$field->value = $blocks->toArray();
		return $field;
	}

	static function translateStructureField(Field $field, string $targetLang, array $blueprintField): Field
	{
		$blueprintFields = $blueprintField['fields'];
		$structure = $field->toStructure();
		$structure = $structure->map(function (StructureObject $object) use ($blueprintFields, $targetLang): array {
			$fieldsArray = array_map(function ($blueprintField) use ($object, $targetLang): mixed {
				return self::translateContent($object->content(), $targetLang, $blueprintField);
			}, $blueprintFields);
			return $fieldsArray;
		});
		$field->value = $structure->values();
		return $field;
	}

	/**
	 * Translates the object field to the target language.
	 *
	 * @param Field $field The field to be translated.
	 * @param string $targetLang The target language code (e.g., 'en', 'fr', 'es').
	 * @param array $blueprintField The blueprint settings for the field. It must contain 'type' and can have 'translate'.
	 *
	 * @return Field The translated field.
	 */
	static function translateObjectField(Field $field, string $targetLang, array $blueprintField): Field
	{
		$blueprintFields = $blueprintField['fields'];
		/** @var Content $object */
		$object = $field->toObject();
		$fieldsArray = array_map(function ($blueprintField) use ($object, $targetLang): mixed {
			return self::translateContent($object, $targetLang, $blueprintField);
		}, $blueprintFields);
		$field->value = $fieldsArray;
		return $field;
	}

	/**
	 * Translates the content to the target language based on the blueprint field’s name.
	 *
	 * @param Content $content The content to be translated.
	 * @param string $targetLang The target language code (e.g., 'en', 'fr', 'es').
	 * @param array $blueprintField The blueprint settings for the field. It must contain 'name', type' items and can have 'translate' item.
	 *
	 * @return mixed The translated content.
	 */
	static function translateContent(Content $content, string $targetLang, array $blueprintField): mixed
	{
		$name = $blueprintField['name'];
		$field = $content->$name();
		$field = $field->translate($targetLang, $blueprintField) ?? null;
		return $field->value ?? null;
	}

	/**
	 * Translates the blocks to the target language based on the provided blueprint field.
	 *
	 * @param Blocks $blocks The blocks to be translated.
	 * @param string $targetLang The target language code (e.g., 'en', 'fr', 'es').
	 * @param array $blueprintField The blueprint settings for the blocks. Can have a 'fieldsets' item. Otherwise the default fieldset for blocks is used.
	 *
	 * @return Blocks The translated blocks.
	 */
	static function translateBlocks(Blocks $blocks, string $targetLang, array $blueprintField): Blocks
	{
		$field = $blocks->field();

		if ($field === null) {
			return $blocks;
		}

		$fieldsets = Fieldsets::factory($blueprintField['fieldsets'] ?? null);
		$blocks->map(function (Block $block) use ($fieldsets, $targetLang): Block
		{
			$fieldset = $fieldsets->get($block->type());
			$translatedFields = array_map(function ($field) use ($fieldset, $targetLang) {
				$fieldsetFields = array_change_key_case($fieldset->fields(), CASE_LOWER);
				$blueprintField = $fieldsetFields[$field->key()] ?? null;
				return $field->translate($targetLang, $blueprintField)->value ?? null;
			}, $block->content()->fields());
			$block->content()->update($translatedFields);
			$array = $block->toArray();

			// remove id from block to guarantee unique ids
			if (array_key_exists('id', $array)) {
				unset($array['id']);
			}
			return Block::factory($array);
		});
		return Blocks::factory($blocks->toArray());
	}

	/**
	 * Translates the given text using Deepl API.
	 * Each request is cached, if cache is enabled. Cached responses are used, if cache is enabled
	 *
	 * @param array $text An array of text to be translated.
	 * @param string $targetLang The target language code (e.g., 'en', 'fr', 'es').
	 * @param string|null $sourceLang (Optional) The source language code (e.g., 'en', 'fr', 'es').
	 *
	 * @return array Each item of the return array has a `detected_source_language` and `text` item.
	 */
	static function translate(array $text, string $targetLang, ?string $sourceLang = null): array
	{
		$options = option('tobiaswolf.machine-translation.deepl');
		$authKey = $options['authKey'];

		$cache = kirby()->cache('tobiaswolf.machine-translation.translate');

		if ($cache->enabled()) {
			$cacheKey = md5(serialize(compact('text', 'targetLang', 'sourceLang', 'options')));
			$cachedResponse = $cache->get($cacheKey);

			if ($cachedResponse){
				return $cachedResponse;
			}
		}

		if (empty($authKey)) {
			throw new Exception('Missing Deepl auth key.');
		}

		$apiDomain = self::DEEPL_API_DOMAIN;
		if (Str::endsWith($authKey, ':fx')) {
			$apiDomain = self::DEEPL_API_FREE_DOMAIN;
		}
		$url = 'https://' . $apiDomain . '/v2/translate';


		$data = [
			'text' => $text,
			'source_lang' => $sourceLang,
			'target_lang' => $targetLang,
			'split_sentences' => $options['split_sentences'],
			'preserve_formatting' => $options['preserve_formatting'],
			'formality' => $options['formality'],
			'glossary_id' => $options['glossary_id'],
			'tag_handling' => $options['tag_handling'],
			'outline_detection' => $options['outline_detection'],
			'non_splitting_tags' => $options['non_splitting_tags'],
			'splitting_tags' => $options['splitting_tags'],
			'ignore_tags' => $options['ignore_tags'],
		];

		$params = [
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
				'Authorization' => 'DeepL-Auth-Key ' . $authKey,
			],
			'data' => json_encode($data),
		];

		$response = Remote::request($url, $params);
		$response = $response->json();
		if (!array_key_exists('translations', $response)) {
			throw new Exception($response['message'] ?? 'Fatal error with Deepl API');
		}

		$translations = $response['translations'];

		if ($cache->enabled()) {
			$cache->set($cacheKey, $translations);
		}

		return $translations;
	}
}