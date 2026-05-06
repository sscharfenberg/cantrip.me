<?php

namespace App\Http\Requests\Decks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the deck CSV import page (GET). Any authenticated user
 * can reach the page — the form decides per-source whether a target
 * deck is needed (Archidekt) or a fresh one is created (cantrip), and
 * those checks happen on POST.
 */
class ShowDeckImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
