/**
 * A JSON Schema validator over the exact subset `schema.ts` uses — no library, on purpose.
 *
 * A runtime dependency in the browser bundle has a price, and `CalendarIsTheOnlyConverterTest`'s
 * `PACKAGE_RUNTIME_DEPENDENCY_ALLOW_LIST` is deliberately empty: the first entry there is a
 * decision somebody should have to write down, and it would not be written down for this.
 *
 * ## The characteristic failure of a hand-written validator, and the guard against it
 *
 * It is not a wrong answer. It is silently IGNORING a keyword it does not implement — the schema
 * then carries a constraint that does nothing, reads as a control in review, and is green on the
 * data it exists to refuse. That is rulings 41 and 49's shape, one layer inside the engine.
 *
 * {@link keywordsUsedBy} exists for the guard rather than for the validator: `contract.test.ts`
 * asserts BOTH directions — every keyword the schema uses is in {@link ASSERTION_KEYWORDS} or
 * {@link ANNOTATION_KEYWORDS}, and every keyword in {@link ASSERTION_KEYWORDS} is used somewhere in
 * the schema. The first direction stops a constraint that does nothing; the second stops dead
 * validator code sitting behind it, which is how the first direction eventually gets weakened.
 *
 * PLANTED, both directions, at P2 Task 7. `maxItems: 7` was added to the schema's `weekendDays`
 * and the first direction went red naming `maxItems`; `'maxItems'` was then added to
 * {@link ASSERTION_KEYWORDS} with the schema untouched and the second direction went red naming it
 * too. Reverted. A guard extended into new territory reads identically to one that is not looking,
 * and this one had never been looked at before.
 *
 * ## What is deliberately NOT implemented
 *
 * `allOf`/`anyOf`/`not`, `patternProperties`, `dependentRequired`, numeric `multipleOf`,
 * `exclusiveMinimum`, `$dynamicRef`, remote `$ref`. None is used by the contract, and the
 * both-directions guard means adding one to the schema fails the build until it is implemented
 * here — which is the property worth having, rather than a validator that quietly does less than
 * the document it is handed claims.
 */

import { CONTRACT_SCHEMA, type JsonSchema } from './schema';

/** Where a value failed, and how. `path` is a JSON-pointer-ish trail from the named def. */
export interface ValidationError {
    path: string;
    message: string;
}

/**
 * Keywords that CONSTRAIN a value. Every one is implemented by {@link checkAgainst} below, and
 * `contract.test.ts` asserts every one is actually used by the contract schema.
 */
export const ASSERTION_KEYWORDS: readonly string[] = [
    '$ref',
    'additionalProperties',
    'const',
    'enum',
    'items',
    'maximum',
    'minItems',
    'minimum',
    'oneOf',
    'pattern',
    'properties',
    'required',
    'type',
];

/** Keywords that describe rather than constrain. Ignored by the validator, by design. */
export const ANNOTATION_KEYWORDS: readonly string[] = ['$schema', '$id', '$defs', 'title', 'description'];

/** Keywords whose VALUES are themselves schemas, and how to reach them. */
const CHILD_SCHEMA_KEYWORDS: readonly string[] = ['properties', '$defs', 'items', 'additionalProperties', 'oneOf'];

/**
 * Every keyword appearing anywhere in a schema document.
 *
 * The walk knows the difference between a keyword and a PROPERTY NAME, which is the whole
 * difficulty: `properties: { type: … }` describes an object with a field called `type`, and a
 * naive `Object.keys` recursion would report `type` as a keyword there and, worse, would miss a
 * genuinely unimplemented keyword nested one level deeper.
 */
export function keywordsUsedBy(schema: JsonSchema): Set<string> {
    const used = new Set<string>();

    const walk = (node: JsonSchema): void => {
        for (const [keyword, value] of Object.entries(node as Record<string, unknown>)) {
            used.add(keyword);

            if (!CHILD_SCHEMA_KEYWORDS.includes(keyword) || value === null || typeof value !== 'object') {
                continue;
            }

            if (keyword === 'properties' || keyword === '$defs') {
                for (const child of Object.values(value as Record<string, JsonSchema>)) {
                    walk(child);
                }
            } else if (keyword === 'oneOf') {
                for (const child of value as JsonSchema[]) {
                    walk(child);
                }
            } else {
                walk(value as JsonSchema);
            }
        }
    };

    walk(schema);

    return used;
}

/** Validate `value` against the named `$defs` entry. An empty array means it is well-formed. */
export function validate(defName: string, value: unknown): ValidationError[] {
    const def = CONTRACT_SCHEMA.$defs[defName];

    if (def === undefined) {
        throw new RangeError(`No contract definition named "${defName}".`);
    }

    const errors: ValidationError[] = [];

    checkAgainst(def, value, `#/${defName}`, errors);

    return errors;
}

/**
 * The same validator against a schema supplied by the caller — what a condition type's
 * `paramsSchema` needs (P2 Task 9).
 *
 * A params schema is not a `$defs` entry of the contract document and must not become one: the
 * contract is CG-10's shape, which every consumer shares, while a type's parameters are that
 * type's own and arrive one department at a time. `$ref` inside a params schema still resolves
 * against the contract document, so a parameter may reuse `Ymd` without copying its pattern.
 */
export function validateAgainst(schema: JsonSchema, value: unknown): ValidationError[] {
    const errors: ValidationError[] = [];

    checkAgainst(schema, value, '#', errors);

    return errors;
}

/**
 * {@link validateAgainst}, but a failure throws — the boundary a department's own numbers cross.
 *
 * Loud rather than lenient, for the reason `strict` and `noUncheckedIndexedAccess` are on: a
 * parameter read as the wrong shape produces a plausible wrong NUMBER rather than a crash, and a
 * plausible wrong number on a rota is a person working a night they should not have.
 */
export function assertValidAgainst(schema: JsonSchema, value: unknown, label: string): void {
    const errors = validateAgainst(schema, value);

    if (errors.length > 0) {
        throw new TypeError(
            `${label} does not satisfy its parameter schema:\n` +
                errors.map((error) => `  ${error.path}: ${error.message}`).join('\n'),
        );
    }
}

/** {@link validate}, but a failure throws — for a boundary where carrying on is not an option. */
export function assertValid(defName: string, value: unknown): void {
    const errors = validate(defName, value);

    if (errors.length > 0) {
        throw new TypeError(
            `Value does not satisfy the contract definition "${defName}":\n` +
                errors.map((error) => `  ${error.path}: ${error.message}`).join('\n'),
        );
    }
}

function typeMatches(expected: string, value: unknown): boolean {
    switch (expected) {
        case 'null':
            return value === null;
        case 'array':
            return Array.isArray(value);
        case 'object':
            return typeof value === 'object' && value !== null && !Array.isArray(value);
        case 'integer':
            return typeof value === 'number' && Number.isInteger(value);
        case 'number':
            return typeof value === 'number' && Number.isFinite(value);
        case 'string':
            return typeof value === 'string';
        case 'boolean':
            return typeof value === 'boolean';
        default:
            throw new RangeError(`The contract schema names an unknown type "${expected}".`);
    }
}

function checkAgainst(schema: JsonSchema, value: unknown, path: string, errors: ValidationError[]): void {
    if (schema.$ref !== undefined) {
        const name = schema.$ref.replace('#/$defs/', '');
        const target = CONTRACT_SCHEMA.$defs[name];

        if (target === undefined) {
            throw new RangeError(`The contract schema references "${schema.$ref}", which does not exist.`);
        }

        checkAgainst(target, value, path, errors);
    }

    if (schema.type !== undefined && !typeMatches(schema.type, value)) {
        errors.push({ path, message: `expected ${schema.type}, got ${describe(value)}` });

        return;
    }

    if (schema.const !== undefined && value !== schema.const) {
        errors.push({ path, message: `expected the constant ${JSON.stringify(schema.const)}` });
    }

    if (schema.enum !== undefined && !schema.enum.includes(value)) {
        errors.push({ path, message: `${describe(value)} is not one of ${JSON.stringify(schema.enum)}` });
    }

    if (schema.pattern !== undefined && typeof value === 'string' && !new RegExp(schema.pattern).test(value)) {
        errors.push({ path, message: `${JSON.stringify(value)} does not match ${schema.pattern}` });
    }

    if (schema.minimum !== undefined && typeof value === 'number' && value < schema.minimum) {
        errors.push({ path, message: `${value} is below the minimum ${schema.minimum}` });
    }

    if (schema.maximum !== undefined && typeof value === 'number' && value > schema.maximum) {
        errors.push({ path, message: `${value} is above the maximum ${schema.maximum}` });
    }

    if (Array.isArray(value)) {
        if (schema.minItems !== undefined && value.length < schema.minItems) {
            errors.push({ path, message: `holds ${value.length} entries, fewer than the ${schema.minItems} required` });
        }

        if (schema.items !== undefined) {
            value.forEach((entry, index) => {
                checkAgainst(schema.items as JsonSchema, entry, `${path}/${index}`, errors);
            });
        }
    }

    if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
        checkObject(schema, value as Record<string, unknown>, path, errors);
    }

    if (schema.oneOf !== undefined) {
        const matches = schema.oneOf.filter((member) => errorsFor(member, value).length === 0);

        if (matches.length !== 1) {
            errors.push({
                path,
                message:
                    matches.length === 0
                        ? `matches none of the ${schema.oneOf.length} permitted shapes`
                        : `matches ${matches.length} of the permitted shapes, which must be exactly one`,
            });
        }
    }
}

function checkObject(
    schema: JsonSchema,
    value: Record<string, unknown>,
    path: string,
    errors: ValidationError[],
): void {
    for (const name of schema.required ?? []) {
        if (!Object.hasOwn(value, name)) {
            errors.push({ path, message: `is missing the required property "${name}"` });
        }
    }

    for (const [name, entry] of Object.entries(value)) {
        const child = schema.properties?.[name];

        if (child !== undefined) {
            checkAgainst(child, entry, `${path}/${name}`, errors);

            continue;
        }

        if (schema.additionalProperties === false) {
            errors.push({ path, message: `carries an unknown property "${name}"` });
        } else if (typeof schema.additionalProperties === 'object') {
            checkAgainst(schema.additionalProperties, entry, `${path}/${name}`, errors);
        }
    }
}

/** {@link checkAgainst} with its errors thrown away — what `oneOf` needs to count matches. */
function errorsFor(schema: JsonSchema, value: unknown): ValidationError[] {
    const errors: ValidationError[] = [];

    checkAgainst(schema, value, '#', errors);

    return errors;
}

function describe(value: unknown): string {
    if (value === null) {
        return 'null';
    }

    if (Array.isArray(value)) {
        return 'an array';
    }

    return typeof value === 'string' ? JSON.stringify(value) : String(value);
}
