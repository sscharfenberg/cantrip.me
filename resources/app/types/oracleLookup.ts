/******************************************************************************
 * Response of the welcome page's Oracle card lookup (`/api/cards/lookup`),
 * built by `App\Services\OracleCardLookupService::search()`.
 *****************************************************************************/

/** One oracle card the lookup found. */
export type OracleLookupCard = {
    /** Oracle id. */
    id: string;
    name: string;
    /** WUBRG letters, or null for a colorless card. */
    color_identity: string | null;
    /**
     * The printing the detail modal shows: the one a `set:` / `number:`
     * search names, else the card's first regular paper printing. Null when
     * the card has no printing at all.
     */
    printing_id: string | null;
    /** One entry per face, front first. */
    faces: { type_line: string | null; mana_cost: string | null }[];
};

export type OracleLookupResponse = {
    cards: OracleLookupCard[];
    /** True when more cards matched than the lookup returns. */
    truncated: boolean;
};
