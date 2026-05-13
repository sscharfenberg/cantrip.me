/**
 * Capability bag describing a format's construction rules.
 *
 * Shape mirrors `App\Formats\FormatProfile::toArray()` on the backend. Vue
 * components switch on these flags rather than on format names so adding a
 * new format only requires backend changes.
 */
export type FormatCapabilities = {
    format: string;
    minDeckSize: number;
    maxDeckSize: number | null;
    maxSideboardSize: number;
    maxCopies: number;
    isSingleton: boolean;
    requiresCommander: boolean;
    maxCommanders: number;
    enforcesColorIdentity: boolean;
    hasSignatureSpell: boolean;
    companionPlacement: "sideboard" | "outside";
    usesGameChangerList: boolean;
};
