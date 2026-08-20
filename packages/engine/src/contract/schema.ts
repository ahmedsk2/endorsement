/**
 * The CG-10 contract as a JSON Schema document — the runtime half of `types.ts`.
 *
 * ## Why a schema at all, when the types are right there
 *
 * TypeScript checks the compiler's inputs. It checks nothing at all about a fixture file, a
 * `POST` body from `App\Support\Engine`, or the JSON arriving on the Node entrypoint's stdin —
 * and those are the three ways real data actually reaches this package. An engine that trusts
 * an untyped object and produces a plausible wrong number is the defect shape
 * `noUncheckedIndexedAccess` is on for, one layer out.
 *
 * ## Why it is a `.ts` file and not a `.json` one
 *
 * The plan names `schema.json`. The tree already refuses that, and says why: `index.ts` records
 * that a JSON import "would need `resolveJsonModule` and would resolve differently under the
 * bundler, under plain Node and under `tsc`, which is three answers to a question worth none."
 * Reading it from disk instead is worse — this package ships to the browser (D4/AR-03), where
 * `node:fs` is fatal. So the schema is a frozen object literal in TypeScript, which IS a JSON
 * Schema document by value; only the file extension differs, and both runtimes resolve it
 * identically. The deviation is recorded here rather than quietly taken.
 *
 * ## The keyword subset, and the guard that keeps it honest
 *
 * `validate.ts` implements a SUBSET of JSON Schema — no library, because a runtime dependency in
 * the browser bundle has a cost and `CalendarIsTheOnlyConverterTest` would have to grow its first
 * allow-list entry to permit one. A hand-written validator's characteristic failure is silently
 * IGNORING a keyword it does not implement, which turns a schema constraint into a control that
 * appears to do nothing — this codebase's most expensive recurring shape (rulings 41 and 49).
 *
 * So `test/contract.test.ts` asserts the two directions: every keyword appearing anywhere in this
 * document is one `validate.ts` implements, and every keyword `validate.ts` implements appears
 * somewhere in this document. The first stops a constraint doing nothing; the second stops dead
 * validator code accumulating behind it.
 */

/** A node of the subset. Deliberately loose — the keyword guard, not this type, is the control. */
export interface JsonSchema {
    $ref?: string;
    type?: string;
    const?: unknown;
    enum?: readonly unknown[];
    pattern?: string;
    minimum?: number;
    maximum?: number;
    minItems?: number;
    items?: JsonSchema;
    properties?: Record<string, JsonSchema>;
    required?: readonly string[];
    additionalProperties?: boolean | JsonSchema;
    oneOf?: readonly JsonSchema[];
    description?: string;
}

/** The whole document: annotations, and the `$defs` every `validate()` call names one of. */
export interface JsonSchemaDocument extends JsonSchema {
    $schema: string;
    $id: string;
    title: string;
    $defs: Record<string, JsonSchema>;
}

const ref = (name: string): JsonSchema => ({ $ref: `#/$defs/${name}` });

/** `T | null`, spelled through `oneOf` so the validator needs no array-valued `type`. */
const nullable = (name: string): JsonSchema => ({ oneOf: [ref(name), { type: 'null' }] });

const ymdList: JsonSchema = { type: 'array', items: ref('Ymd') };

export const CONTRACT_SCHEMA: JsonSchemaDocument = {
    $schema: 'https://json-schema.org/draft/2020-12/schema',
    $id: 'https://munawib.local/schema/cg-10-contract.json',
    title: 'CG-10 conditions engine contract',
    description:
        'The shapes evaluate() and coverage() are called with and answer in. Authored in full at ' +
        'P2 Task 7, before the first predicate, so that P2-2 adds types and touches no shared shape.',
    $defs: {
        Ymd: {
            description: 'A civil date. A string that sorts correctly as text, and holds no instant.',
            type: 'string',
            pattern: '^[0-9]{4}-[0-9]{2}-[0-9]{2}$',
        },

        Span: {
            description: 'A dated span of one fact about a person. `key` is stable, never a database id.',
            type: 'object',
            required: ['key', 'from', 'to'],
            additionalProperties: false,
            properties: { key: { type: 'string' }, from: ref('Ymd'), to: ref('Ymd') },
        },

        Slot: {
            description: 'P2 authors the duty shape ahead of SL-01..SL-07. `key` and `kind` are opaque.',
            type: 'object',
            required: [
                'key',
                'kind',
                'cadence',
                'spanDays',
                'startMinute',
                'endMinute',
                'crossesMidnight',
                'countsHours',
            ],
            additionalProperties: false,
            properties: {
                key: { type: 'string' },
                kind: { type: 'string' },
                unitKey: { type: 'string' },
                cadence: { enum: ['daily', 'weekly'] },
                spanDays: { type: 'integer', minimum: 1 },
                startMinute: { type: 'integer', minimum: 0, maximum: 1439 },
                endMinute: { type: 'integer', minimum: 0, maximum: 1440 },
                crossesMidnight: { type: 'boolean' },
                countsHours: { type: 'boolean' },
                tallyKey: { type: 'string' },
            },
        },

        Duty: {
            description: 'One person, one date, one slot. The whole of it.',
            type: 'object',
            required: ['personKey', 'date', 'slotKey'],
            additionalProperties: false,
            properties: { personKey: { type: 'string' }, date: ref('Ymd'), slotKey: { type: 'string' } },
        },

        Horizon: {
            description: 'What is evaluated, and how far the read-only carry-in tail reaches.',
            type: 'object',
            required: ['from', 'to', 'evaluableFrom', 'evaluableTo'],
            additionalProperties: false,
            properties: {
                from: ref('Ymd'),
                to: ref('Ymd'),
                evaluableFrom: ref('Ymd'),
                evaluableTo: ref('Ymd'),
            },
        },

        Day: {
            description: 'One date of the horizon, precomputed by the one converter. dayType is never re-derived.',
            type: 'object',
            required: ['date', 'isoWeekday', 'dayType', 'periodKey', 'holidays'],
            additionalProperties: false,
            properties: {
                date: ref('Ymd'),
                isoWeekday: { type: 'integer', minimum: 1, maximum: 7 },
                dayType: { enum: ['WD', 'WE', 'HOL'] },
                periodKey: { oneOf: [{ type: 'string' }, { type: 'null' }] },
                holidays: {
                    type: 'array',
                    items: {
                        type: 'object',
                        required: ['key', 'year'],
                        additionalProperties: false,
                        properties: { key: { type: 'string' }, year: { type: 'integer' } },
                    },
                },
            },
        },

        Week: {
            description: 'A week with the clipped bounds the department rule produced, server-side.',
            type: 'object',
            required: ['startsOn', 'endsOn', 'clippedStartsOn', 'clippedEndsOn'],
            additionalProperties: false,
            properties: {
                startsOn: ref('Ymd'),
                endsOn: ref('Ymd'),
                clippedStartsOn: ref('Ymd'),
                clippedEndsOn: ref('Ymd'),
            },
        },

        Period: {
            type: 'object',
            required: ['key', 'startsOn', 'endsOn', 'weeks'],
            additionalProperties: false,
            properties: {
                key: { type: 'string' },
                startsOn: ref('Ymd'),
                endsOn: ref('Ymd'),
                weeks: { type: 'array', items: ref('Week') },
            },
        },

        Person: {
            description: 'priorCredits is number|null per holiday key; null means UNKNOWN, not zero.',
            type: 'object',
            required: ['key', 'levelSpans', 'unitSpans', 'leaveDays', 'unwantedDays', 'eligibleDays', 'external'],
            additionalProperties: false,
            properties: {
                key: { type: 'string' },
                levelSpans: { type: 'array', items: ref('Span') },
                unitSpans: { type: 'array', items: ref('Span') },
                leaveDays: ymdList,
                unwantedDays: ymdList,
                eligibleDays: ymdList,
                external: { type: 'boolean' },
                joinedAt: ref('Ymd'),
                priorCredits: {
                    type: 'object',
                    additionalProperties: { oneOf: [{ type: 'number' }, { type: 'null' }] },
                },
            },
        },

        Clinic: {
            type: 'object',
            required: [
                'key',
                'unitKey',
                'isoWeekday',
                'session',
                'active',
                'attendeeMode',
                'attendeeLevelKeys',
                'attendeePersonKeys',
            ],
            additionalProperties: false,
            properties: {
                key: { type: 'string' },
                unitKey: { type: 'string' },
                isoWeekday: { type: 'integer', minimum: 1, maximum: 7 },
                session: { type: 'string' },
                active: { type: 'boolean' },
                attendeeMode: { enum: ['rotators', 'levels', 'named'] },
                attendeeLevelKeys: { type: 'array', items: { type: 'string' } },
                attendeePersonKeys: { type: 'array', items: { type: 'string' } },
            },
        },

        EvaluationContext: {
            description: 'Everything the engine is told. timezone is provenance and is read by nothing.',
            type: 'object',
            required: [
                'timezone',
                'weekStartIsoDay',
                'weekendDays',
                'today',
                'days',
                'periods',
                'people',
                'slots',
                'clinics',
                'historyAvailableFrom',
                'priorDuties',
                'followingDuties',
            ],
            additionalProperties: false,
            properties: {
                timezone: { type: 'string' },
                weekStartIsoDay: { type: 'integer', minimum: 1, maximum: 7 },
                weekendDays: { type: 'array', items: { type: 'integer', minimum: 1, maximum: 7 } },
                today: ref('Ymd'),
                days: { type: 'array', items: ref('Day') },
                periods: { type: 'array', items: ref('Period') },
                people: { type: 'array', items: ref('Person') },
                slots: { type: 'array', items: ref('Slot') },
                clinics: { type: 'array', items: ref('Clinic') },
                historyAvailableFrom: nullable('Ymd'),
                priorDuties: { type: 'array', items: ref('Duty') },
                followingDuties: { type: 'array', items: ref('Duty') },
            },
        },

        Schedule: {
            type: 'object',
            required: ['horizon', 'duties'],
            additionalProperties: false,
            properties: { horizon: ref('Horizon'), duties: { type: 'array', items: ref('Duty') } },
        },

        Severity: {
            description: 'CG-05 Hard and CG-06 soft, and nothing else. A third member collides with authored class.',
            enum: ['hard', 'soft'],
        },

        ConditionScope: {
            type: 'object',
            additionalProperties: false,
            properties: {
                unitKeys: { type: 'array', items: { type: 'string' } },
                levelKeys: { type: 'array', items: { type: 'string' } },
                personKeys: { type: 'array', items: { type: 'string' } },
            },
        },

        Condition: {
            description: 'One row of the CG-01 gate. class is authored; the engine reads it and never sets it.',
            type: 'object',
            required: ['id', 'typeKey', 'class', 'active', 'params'],
            additionalProperties: false,
            properties: {
                id: { type: 'string' },
                typeKey: { type: 'string' },
                class: ref('Severity'),
                rank: { type: 'integer', minimum: 1 },
                active: { type: 'boolean' },
                source: { type: 'string' },
                scope: ref('ConditionScope'),
                params: { type: 'object' },
            },
        },

        Location: {
            description:
                'The three shapes this catalog violates at. contributing is MANDATORY on a window ' +
                'violation — WB-03 badges a cell and WB-04 orders a picker, and neither can act on a ' +
                'range — but it MAY BE EMPTY, which is a floor answering the question rather than ' +
                'failing to. See the window member below.',
            oneOf: [
                {
                    type: 'object',
                    required: ['kind', 'personKey', 'date', 'slotKey'],
                    additionalProperties: false,
                    properties: {
                        kind: { const: 'placement' },
                        personKey: { type: 'string' },
                        date: ref('Ymd'),
                        slotKey: { type: 'string' },
                    },
                },
                {
                    type: 'object',
                    required: ['kind', 'personKey', 'from', 'to', 'contributing'],
                    additionalProperties: false,
                    properties: {
                        kind: { const: 'window' },
                        personKey: { type: 'string' },
                        from: ref('Ymd'),
                        to: ref('Ymd'),
                        // CORRECTED AT P2-2 TASK 15, BY THE FIRST FLOOR THAT FIRED. This carried
                        // `minItems: 1`, authored at Task 7 on the argument that a duty-hours
                        // violation naming no duty is unactionable. That argument is right for a
                        // CAP and exactly inverted for a FLOOR: `count_min` fires hardest on the
                        // person who holds NOTHING, and an empty list is that person's whole
                        // answer rather than a missing field. The KEY stays mandatory, which is
                        // the half Task 7 was protecting — `contributing` absent means a type
                        // forgot to say, `contributing: []` means a type said none — and
                        // `contract.test.ts` now asserts both halves separately so the
                        // distinction cannot collapse back into one check. The union, the five
                        // fields of `Violation` and `evaluate()`'s return type are untouched.
                        contributing: { type: 'array', items: ref('Duty') },
                    },
                },
                {
                    type: 'object',
                    required: ['kind', 'personKeys', 'scopeLabel'],
                    additionalProperties: false,
                    properties: {
                        kind: { const: 'cohort' },
                        personKeys: { type: 'array', minItems: 1, items: { type: 'string' } },
                        scopeLabel: { type: 'string' },
                        contributing: { type: 'array', items: ref('Duty') },
                    },
                },
            ],
        },

        Violation: {
            description: 'Exactly CG-10 five fields. typeKey is deliberately not among them.',
            type: 'object',
            required: ['conditionId', 'severity', 'location', 'explanation'],
            additionalProperties: false,
            properties: {
                conditionId: { type: 'string' },
                severity: ref('Severity'),
                rank: { type: 'integer', minimum: 1 },
                location: ref('Location'),
                explanation: { type: 'string' },
            },
        },

        Finding: {
            description: 'What a type produces before evaluate() stamps the condition own facts on.',
            type: 'object',
            required: ['location', 'explanation'],
            additionalProperties: false,
            properties: { location: ref('Location'), explanation: { type: 'string' } },
        },

        SkippedWindow: {
            type: 'object',
            required: ['from', 'to', 'reason'],
            additionalProperties: false,
            properties: { from: ref('Ymd'), to: ref('Ymd'), reason: { type: 'string' } },
        },

        CoverageDetail: {
            type: 'object',
            required: ['evaluatedWindows', 'skipped'],
            additionalProperties: false,
            properties: {
                evaluatedWindows: { type: 'integer', minimum: 0 },
                skipped: { type: 'array', items: ref('SkippedWindow') },
            },
        },

        CoverageReport: {
            description: 'coverage() row: a skipped window is REPORTED, never dropped.',
            type: 'object',
            required: ['conditionId', 'evaluatedWindows', 'skipped'],
            additionalProperties: false,
            properties: {
                conditionId: { type: 'string' },
                evaluatedWindows: { type: 'integer', minimum: 0 },
                skipped: { type: 'array', items: ref('SkippedWindow') },
            },
        },

        Fixture: {
            description:
                'One corpus case. `why` is mandatory: a fixture whose purpose nobody wrote down is ' +
                'a fixture nobody dares change. The corpus is synthetic, permanently.',
            type: 'object',
            required: ['name', 'why', 'context', 'schedule', 'conditions', 'expected'],
            additionalProperties: false,
            properties: {
                name: { type: 'string' },
                why: { type: 'string' },
                context: ref('EvaluationContext'),
                schedule: ref('Schedule'),
                conditions: { type: 'array', items: ref('Condition') },
                expected: { type: 'array', items: ref('Violation') },
                expectedCoverage: { type: 'array', items: ref('CoverageReport') },
            },
        },
    },
};
