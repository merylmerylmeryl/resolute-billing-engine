<?php

namespace App\Billing;

use App\Enums\EventStatus;
use App\Models\Client;
use App\Models\Event;
use App\Models\Jurisdiction;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonException;

/**
 * Loads properties and events from a JSON file.
 *
 * A file it can't use aborts before anything is written. A bad row is skipped and
 * reported, and the rest still load. Rows are handled in file order and never sorted,
 * because "recorded last" for same-date events depends on the id they get here.
 */
class DataImporter
{
    /**
     * What a decimal(14,2) column holds. SQLite doesn't enforce it, and a value outside
     * it would read back changed through the decimal:2 cast, altering the fee.
     */
    private const MONEY_FORMAT = 'regex:/\A\d{1,12}(\.\d{1,2})?\z/';

    /**
     * @var array{clients_created: int, properties_created: int, properties_updated: int, events_created: int, duplicates_skipped: int, rejected: list<string>, warnings: list<string>}
     */
    private array $summary;

    /**
     * @var array<string, int>
     */
    private array $jurisdictionIds;

    /**
     * @return array{clients_created: int, properties_created: int, properties_updated: int, events_created: int, duplicates_skipped: int, rejected: list<string>, warnings: list<string>}
     *
     * @throws InvalidArgumentException when the file is unreadable, isn't JSON or lacks a top-level key.
     */
    public function import(string $path): array
    {
        $data = $this->readFile($path);

        $this->summary = [
            'clients_created' => 0,
            'properties_created' => 0,
            'properties_updated' => 0,
            'events_created' => 0,
            'duplicates_skipped' => 0,
            'rejected' => [],
            'warnings' => [],
        ];
        $this->jurisdictionIds = Jurisdiction::pluck('id', 'code')->all();

        DB::transaction(function () use ($data) {
            foreach ($data['properties'] as $index => $row) {
                $this->importProperty("properties[{$index}]", $row);
            }

            foreach ($data['events'] as $index => $row) {
                $this->importEvent("events[{$index}]", $row);
            }
        });

        return $this->summary;
    }

    /**
     * @return array{properties: array<mixed>, events: array<mixed>}
     */
    private function readFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Could not read [{$path}].");
        }

        try {
            $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException("[{$path}] is not valid JSON.");
        }

        foreach (['properties', 'events'] as $key) {
            if (! is_array($data[$key] ?? null)) {
                throw new InvalidArgumentException("[{$path}] needs a top-level [{$key}] list.");
            }
        }

        return $data;
    }

    private function importProperty(string $label, mixed $row): void
    {
        if (! is_array($row)) {
            $this->reject($label, 'The row must be an object.');

            return;
        }

        $row = $this->withStringIds($row, ['id', 'client_id']);
        $label .= $this->rowId($row, 'id');

        $validator = Validator::make($row, [
            'id' => ['required', 'string'],
            'client_id' => ['required', 'string'],
            'homestead' => ['required', 'boolean'],
            'jurisdictions' => ['required', 'array'],
            'jurisdictions.*' => ['string'],
        ]);

        if ($validator->fails()) {
            $this->reject($label, $validator->errors()->first());

            return;
        }

        $unknownCodes = array_diff($row['jurisdictions'], array_keys($this->jurisdictionIds));

        if ($unknownCodes !== []) {
            $this->reject($label, 'Unknown jurisdiction ['.implode(', ', $unknownCodes).'].');

            return;
        }

        $existing = Property::with('client')->firstWhere('external_id', $row['id']);

        if ($existing !== null && $existing->client->external_id !== $row['client_id']) {
            $this->reject($label, "It belongs to client [{$existing->client->external_id}] and can't move to [{$row['client_id']}].");

            return;
        }

        $client = Client::updateOrCreate(['external_id' => $row['client_id']]);

        if ($client->wasRecentlyCreated) {
            $this->summary['clients_created']++;
        }

        $property = Property::updateOrCreate(
            ['external_id' => $row['id']],
            ['client_id' => $client->id, 'homestead' => $row['homestead']],
        );

        $jurisdictionChanges = $property->jurisdictions()->sync(
            array_map(fn (string $code) => $this->jurisdictionIds[$code], $row['jurisdictions']),
        );

        if ($property->wasRecentlyCreated) {
            $this->summary['properties_created']++;
        } elseif ($property->wasChanged() || $jurisdictionChanges['attached'] !== [] || $jurisdictionChanges['detached'] !== []) {
            $this->summary['properties_updated']++;
        }
    }

    private function importEvent(string $label, mixed $row): void
    {
        if (! is_array($row)) {
            $this->reject($label, 'The row must be an object.');

            return;
        }

        $row = $this->withStringIds($row, ['source_id', 'property_id']);
        $label .= $this->rowId($row, 'source_id');

        $row['noticed_value'] = $this->moneyString($row['noticed_value'] ?? null);
        $row['final_value'] = $this->moneyString($row['final_value'] ?? null);

        $validator = Validator::make($row, [
            'source_id' => ['required', 'string'],
            'property_id' => ['required', 'string'],
            'recorded_at' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::enum(EventStatus::class)],
            'noticed_value' => ['required', self::MONEY_FORMAT],
            'final_value' => ['nullable', 'required_if:status,final', 'prohibited_if:status,pending', self::MONEY_FORMAT],
        ], [
            'regex' => 'The :attribute field must be a non-negative decimal with at most 12 whole digits and 2 decimal places.',
        ]);

        if ($validator->fails()) {
            $this->reject($label, $validator->errors()->first());

            return;
        }

        $propertyId = Property::where('external_id', $row['property_id'])->value('id');

        if ($propertyId === null) {
            $this->reject($label, "Unknown property [{$row['property_id']}].");

            return;
        }

        $values = [
            'property_id' => $propertyId,
            'recorded_at' => $row['recorded_at'],
            'status' => $row['status'],
            'noticed_value' => $row['noticed_value'],
            'final_value' => $row['final_value'],
        ];

        $existing = Event::firstWhere('source_id', $row['source_id']);

        if ($existing !== null) {
            $this->summary['duplicates_skipped']++;

            if (! $this->hasSameValues($existing, $values)) {
                $this->summary['warnings'][] = "{$label}: This source_id repeats with different values, so the first one was kept.";
            }

            return;
        }

        Event::create(['source_id' => $row['source_id'], ...$values]);

        $this->summary['events_created']++;
    }

    /**
     * Converts integer ids to strings, so 101 and "101" are the same record. Any other
     * type is left for validation to reject.
     *
     * @param  array<mixed>  $row
     * @param  list<string>  $keys
     * @return array<mixed>
     */
    private function withStringIds(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            if (is_int($row[$key] ?? null)) {
                $row[$key] = (string) $row[$key];
            }
        }

        return $row;
    }

    /**
     * Turns a JSON number into a string, the one place a float can enter, before it is
     * validated. json_encode gives the shortest form that reads back as the same float,
     * while a string cast rounds to the `precision` setting. json_decode has already made
     * it a float, so validation sees that form and not the file's text: a number with
     * about 17 or more digits, like 100.12999999999999999, passes as 100.13. A string is
     * checked as written. A blank string means no value. Anything else is left for
     * validation to reject.
     */
    private function moneyString(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return json_encode($value);
        }

        return is_string($value) && trim($value) === '' ? null : $value;
    }

    /**
     * @param  array{property_id: int, recorded_at: string, status: string, noticed_value: string, final_value: ?string}  $values
     */
    private function hasSameValues(Event $event, array $values): bool
    {
        return $event->property_id === $values['property_id']
            && $event->recorded_at->toDateString() === $values['recorded_at']
            && $event->status->value === $values['status']
            && $event->noticed_value === bcadd($values['noticed_value'], '0', 2)
            && $event->final_value === ($values['final_value'] === null ? null : bcadd($values['final_value'], '0', 2));
    }

    /**
     * @param  array<mixed>  $row
     */
    private function rowId(array $row, string $key): string
    {
        return is_string($row[$key] ?? null) ? " ({$row[$key]})" : '';
    }

    private function reject(string $label, string $reason): void
    {
        $this->summary['rejected'][] = "{$label}: {$reason}";
    }
}
