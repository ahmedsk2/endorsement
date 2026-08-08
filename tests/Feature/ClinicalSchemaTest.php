<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema contract for the two clinical tables (spec §4). Guards both directions:
 * columns that must EXIST (incl. the per-unit legacy columns and the lossless-import
 * provenance pair) and columns that must NOT (the dropped `draft` flag).
 */
class ClinicalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_handovers_carries_the_full_spec_column_set(): void
    {
        foreach ([
            'id', 'institution_id', 'unit_id', 'handover_date',
            'bed', 'mrn', 'patient_name',
            'dob', 'age', 'ward_unit',            // per-unit legacy columns — NEVER drop
            'disease', 'details', 'plan', 'nevent',
            'extra_fields',                       // P0b bounded custom fields (EncryptedJson)
            'author_user_id',
            'legacy_source_table', 'legacy_id',   // lossless import provenance
            'created_at', 'updated_at', 'deleted_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('handovers', $column),
                "handovers.{$column} is missing"
            );
        }
    }

    public function test_handovers_has_no_draft_column(): void
    {
        // Spec ruling 8 — the half-built per-row draft flag was deliberately dropped;
        // signed_off_at on the day header is the only workflow state.
        $this->assertFalse(Schema::hasColumn('handovers', 'draft'));
    }

    public function test_handover_signoffs_carries_the_full_spec_column_set(): void
    {
        foreach ([
            'id', 'institution_id', 'unit_id', 'handover_date',
            'endorsed_by_user_id', 'endorsed_by_name',
            'endorsed_to_user_id', 'endorsed_to_name',
            'consultant_by_user_id', 'consultant_by_name',
            'consultant_to_user_id', 'consultant_to_name',
            'endorsement_time', 'endorsement_time_minutes',
            'signed_off_at', 'signed_off_by_user_id',
            'reopened_at', 'reopened_by_user_id', 'reopen_reason',
            'legacy_source_table', 'legacy_id',
            'created_at', 'updated_at', 'deleted_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('handover_signoffs', $column),
                "handover_signoffs.{$column} is missing"
            );
        }
    }
}
