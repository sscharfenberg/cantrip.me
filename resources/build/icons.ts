/******************************************************************************
 * merge svg icon files into a single svg file with symbols
 * CLI options
 * -v verbose output
 * -w watch files for changes
 * -i inline SVG, defaults to false
 *****************************************************************************/
import fs from "node:fs";
import path from "node:path";
import { argv, cwd, exit, hrtime } from "node:process";
import { styleText } from "node:util";
import processAgs from "minimist";
import { optimize } from "svgo";
import svgStore from "svgstore";
const args = processAgs(argv.slice(2));
const PROJECTROOT: string = fs.realpathSync(cwd());
const ICONS_DIR: string = path.resolve(PROJECTROOT, "resources/app/assets/icons");
const INLINE: boolean = args.i || false; // true = no DOCTYPE and xml/xmlns attribute
const OUT_DIR: string = path.resolve(PROJECTROOT, "storage/app/public");
const OUT_PATH: string = path.resolve(OUT_DIR, "sprite.svg");

/** the global prefix for all icon log messages */
const logPrefix: string | false = !args.w && styleText(["bgCyan", "black"], "[" + styleText("italic", "icons") + "]");

/** info prefix for all icon log messages */
const infoPrefix: string = styleText(["bgWhiteBright", "black"], " 🧐 nfo ");

/** debug prefix for all icon log messages */
const debugPrefix: string = styleText(["bgYellow", "black"], " 😏 dbg ");

/** error prefix for all icon log messages */
const errorPrefix: string = styleText(["bgRed", "white"], " 🫣 err ");

/** log object with [info|debug|error] methods */
const log = {
    info: (text: string): void => {
        let msg = "";
        if (!args.w) msg += logPrefix;
        console.log(`${msg}${infoPrefix} ${styleText("whiteBright", text)}`);
    },
    debug: (text: string): void => {
        let msg = "";
        if (!args.w) msg += logPrefix;
        console.log(`${msg}${debugPrefix} ${styleText("yellowBright", text)}`);
    },
    error: (text: string): void => {
        let msg = "";
        if (!args.w) msg += logPrefix;
        console.log(`${msg}${errorPrefix} ${styleText("redBright", text)}`);
    }
};

/** read directory and get filenames of svg files */
const getSvgFileNames = (): string[] => {
    const fileNames = fs.readdirSync(ICONS_DIR).filter(file => file.endsWith(".svg"));
    if (fileNames.length > 0) return fileNames;
    return [];
};

/** write sprite to disk */
const writeSpriteToDisk = (data: string, length: number, start: bigint): void => {
    if (!fs.existsSync(OUT_DIR)) {
        if (args.v) log.info(`creating output directory ${styleText("yellow", path.basename(OUT_DIR))}`);
        fs.mkdirSync(OUT_DIR);
    }
    log.info(`writing icon sprite with ${styleText(["bgRed", "white"], " " + length + " ")} symbols`);
    fs.writeFile(OUT_PATH, data, err => {
        if (err) {
            log.error(err.message);
            exit(1);
        } else {
            log.info(styleText(["bgGreenBright", "black"], " 🥳 success! "));
            const end = hrtime.bigint();
            log.debug(
                `processing took ${styleText(["bgYellow", "black"], " " + formatHrTime(end - start) + " ")} seconds.`
            );
        }
    });
};

/** format HrTime in a human-readable format */
const formatHrTime = (time: bigint): string => {
    return (Number(time) / 1000000000).toFixed(3);
};

/** event handler for firing a watch event */
const onIconChanged = (event: string, fileName: string | null): void => {
    log.info(`file ${styleText(["bgYellow", "black"], " " + fileName + " ")} triggered watcher`);
    createIconSprite();
};

/**
 * watch icon files for changes
 * once triggered, the watcher is killed and one second later resurrected
 * there are two reasons for this:
 * 1) depending on the OS, the watch function is triggered twice - once with "change", once with "rename"
 * 2) if more than one file is copied into the watched directory, the watcher gets triggered once per file.
 */
const watch = (): void => {
    const watcher = fs.watch(ICONS_DIR, { recursive: true }, (event, filename) => {
        watcher.close(); // first, kill the watcher so the event only triggers once.
        onIconChanged(event, filename); // event handler for the watch event
        setTimeout(watch, 1000); // resurrect the watcher after 1s
    });
};

/** main function that creates the icon sprite */
export const createIconSprite = (): void => {
    const svgFiles = getSvgFileNames();
    let counter = 0;
    // prepare svgStore instance
    const sprites = svgStore({
        cleanDefs: true,
        cleanSymbols: true,
        svgAttrs: {
            class: "icon-sprite",
            "aria-hidden": "true",
            xmlns: "http://www.w3.org/2000/svg"
        }
    });
    const time = hrtime.bigint();
    log.info("preparing svg icon sprite sheet.");
    if (args.v) log.info(`found ${styleText(["bgRed", "white"], " " + svgFiles.length + " ")} svg icon files`);
    // only proceed if we do have icons.
    if (svgFiles.length > 0) {
        svgFiles.forEach(svgFile => {
            // loop all icons
            const id = svgFile.replace(/\.[^/.]+$/, "");
            const filePath = path.join(ICONS_DIR, svgFile);
            const fileContents = fs.readFileSync(filePath, { encoding: "utf8" });
            // optimize svg file with svgo
            const optimizedSvg = optimize(fileContents, {
                multipass: true
            }).data;
            if (optimizedSvg && args.v) log.debug(id); // verbose output of icon id
            counter++;
            sprites.add(id, optimizedSvg, { symbolAttrs: { "aria-role": "icon" } }); // add to sprite
            if (counter === svgFiles.length) {
                // if all icons are processed
                writeSpriteToDisk(
                    sprites.toString({
                        inline: INLINE
                    }),
                    svgFiles.length,
                    time
                );
            }
        });
    } else {
        exit(0);
    }
};

/**
 * main script execution via node resources/build/icons.ts
 */
if (args.v) log.debug("verbose output selected");
if (args.w) log.debug("watch option selected");
if (args.i) log.debug("inline option selected");
if (args.w) {
    log.info(`watching icon directory for changes ʕ•ᴥ•ʔ`);
    watch();
} else {
    createIconSprite();
}
