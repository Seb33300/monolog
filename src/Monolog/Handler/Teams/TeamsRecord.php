<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Handler\Teams;

use Monolog\Level;
use Monolog\Utils;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * MS Teams record utility helping to log to MS Teams webhooks.
 *
 * @author Sébastien Alfaiate <s.alfaiate@webarea.fr>
 * @see    https://learn.microsoft.com/adaptive-cards/authoring-cards/getting-started
 */
class TeamsRecord
{
    public const COLOR_ATTENTION = 'attention';

    public const COLOR_WARNING = 'warning';

    public const COLOR_GOOD = 'good';

    public const COLOR_DEFAULT = 'default';

    /**
     * Whether the card should include context and extra data
     */
    private bool $includeContextAndExtra;

    /**
     * Dot separated list of fields to exclude from MS Teams message. E.g. ['context.field1', 'extra.field2']
     * @var string[]
     */
    private array $excludeFields;

    private FormatterInterface|null $formatter;

    private NormalizerFormatter $normalizerFormatter;

    /**
     * @param string[] $excludeFields
     */
    public function __construct(
        bool $includeContextAndExtra = false,
        array $excludeFields = [],
        FormatterInterface|null $formatter = null
    ) {
        $this
            ->includeContextAndExtra($includeContextAndExtra)
            ->excludeFields($excludeFields)
            ->setFormatter($formatter);
    }

    /**
     * Returns required data in format that MS Teams is expecting.
     *
     * @phpstan-return mixed[]
     */
    public function getAdaptiveCardPayload(LogRecord $record): array
    {
        if ($this->formatter !== null) {
            $message = $this->formatter->format($record);
        } else {
            $message = $record->message;
        }

        $recordData = $this->removeExcludedFields($record);

        $facts = [
            $this->generateFactField('Level', $recordData['level_name']),
        ];

        if ($this->includeContextAndExtra) {
            foreach (['extra', 'context'] as $key) {
                if (!isset($recordData[$key]) || \count($recordData[$key]) === 0) {
                    continue;
                }

                $facts = array_merge(
                    $facts,
                    $this->generateFactFields($recordData[$key])
                );
            }
        }

        return [
            'type'        => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content'     => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type'    => 'AdaptiveCard',
                        'version' => '1.5',
                        'body'    => [
                            // Card Header
                            [
                                'type'  => 'Container',
                                'style' => $this->getContainerStyle($record->level),
                                'items' => [
                                    [
                                        'type'   => 'TextBlock',
                                        'text'   => $message,
                                        'weight' => 'Bolder',
                                        'size'   => 'Medium',
                                        'wrap'   => true,
                                    ],
                                ],
                            ],
                            // Context and Extra
                            [
                                'type'    => 'Container',
                                'spacing' => 'Medium',
                                'items'   => [
                                    [
                                        'type'  => 'FactSet',
                                        'facts' => $facts,
                                    ],
                                ],
                            ]
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Returns MS Teams container style associated with provided level.
     */
    public function getContainerStyle(Level $level): string
    {
        return match ($level) {
            Level::Error, Level::Critical, Level::Alert, Level::Emergency => static::COLOR_ATTENTION,
            Level::Warning => static::COLOR_WARNING,
            Level::Info, Level::Notice => static::COLOR_GOOD,
            Level::Debug => static::COLOR_DEFAULT
        };
    }

    /**
     * Stringifies an array of key/value pairs to be used in fact fields
     *
     * @param mixed[] $fields
     */
    public function stringify(array $fields): string
    {
        /** @var array<array<mixed>|bool|float|int|string|null> $normalized */
        $normalized = $this->normalizerFormatter->normalizeValue($fields);

        $hasSecondDimension = \count(array_filter($normalized, 'is_array')) > 0;
        $hasOnlyNonNumericKeys = \count(array_filter(array_keys($normalized), 'is_numeric')) === 0;

        return $hasSecondDimension || $hasOnlyNonNumericKeys
            ? Utils::jsonEncode($normalized, JSON_PRETTY_PRINT|Utils::DEFAULT_JSON_FLAGS)
            : Utils::jsonEncode($normalized, Utils::DEFAULT_JSON_FLAGS);
    }

    /**
     * @return $this
     */
    public function includeContextAndExtra(bool $includeContextAndExtra = false): self
    {
        $this->includeContextAndExtra = $includeContextAndExtra;

        if ($this->includeContextAndExtra) {
            $this->normalizerFormatter = new NormalizerFormatter();
        }

        return $this;
    }

    /**
     * @param  string[] $excludeFields
     * @return $this
     */
    public function excludeFields(array $excludeFields = []): self
    {
        $this->excludeFields = $excludeFields;

        return $this;
    }

    /**
     * @return $this
     */
    public function setFormatter(?FormatterInterface $formatter = null): self
    {
        $this->formatter = $formatter;

        return $this;
    }

    /**
     * Generates fact field
     *
     * @param string|mixed[] $value
     *
     * @return array{title: string, value: string}
     */
    private function generateFactField(string $title, $value): array
    {
        $value = \is_array($value)
            ? sprintf('```%s```', substr($this->stringify($value), 0, 1990))
            : $value;

        return [
            'title' => ucfirst($title),
            'value' => $value,
        ];
    }

    /**
     * Generates a collection of fact fields from array
     *
     * @param mixed[] $data
     *
     * @return array<array{title: string, value: string}>
     */
    private function generateFactFields(array $data): array
    {
        /** @var array<array<mixed>|string> $normalized */
        $normalized = $this->normalizerFormatter->normalizeValue($data);

        $fields = [];
        foreach ($normalized as $key => $value) {
            $fields[] = $this->generateFactField((string) $key, $value);
        }

        return $fields;
    }

    /**
     * Get a copy of record with fields excluded according to $this->excludeFields
     *
     * @return mixed[]
     */
    private function removeExcludedFields(LogRecord $record): array
    {
        $recordData = $record->toArray();
        foreach ($this->excludeFields as $field) {
            $keys = explode('.', $field);
            $node = &$recordData;
            $lastKey = end($keys);
            foreach ($keys as $key) {
                if (!isset($node[$key])) {
                    break;
                }
                if ($lastKey === $key) {
                    unset($node[$key]);
                    break;
                }
                $node = &$node[$key];
            }
        }

        return $recordData;
    }
}
