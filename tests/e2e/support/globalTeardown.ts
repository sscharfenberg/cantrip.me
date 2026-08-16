import { restoreHotFile } from "./environment";

/**
 * Put a stashed `public/hot` back, so the developer's next `npm run dev` still
 * points at their dev server.
 *
 * THE DATABASE CONTAINER IS DELIBERATELY LEFT RUNNING. It holds the state a
 * failure happened in, and being able to open it after a red run
 * (`docker exec -it cantrip-e2e-db-1 mariadb -ucantrip_e2e -pcantrip_e2e cantrip_e2e`)
 * is worth more than a tidy `docker ps`. The next run migrates it fresh anyway;
 * `npm run e2e:db:down` reclaims the RAM when you are done for the day.
 */
export default async function globalTeardown(): Promise<void> {
    restoreHotFile();
}
