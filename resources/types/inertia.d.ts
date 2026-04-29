import type { Type as FlashType } from "Components/UI/FlashMessage.vue";

export {};

declare module "@inertiajs/core" {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: {
                user: {
                    id: number;
                    name: string;
                    email: string;
                    deck_view_default: "text" | "cards";
                    deck_sort_default: "mana" | "name";
                    collection_integration_enabled: boolean;
                } | null;
            };
            locale: string;
            supportedLocales: string[];
            supportedDeckViews: ("text" | "cards")[];
            supportedDeckSorts: ("mana" | "name")[];
            features: {
                registration: boolean;
                resetPasswords: boolean;
                emailVerification: boolean;
            };
            flash: {
                message: string;
                type: FlashType;
                nonce: string | null;
            };
        };
    }
}
