import { build } from "esbuild";
import { mkdir, rm } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";
import { exec as execCb } from "node:child_process";
import { promisify } from "node:util";

const exec = promisify(execCb);
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");
const distDir = path.join(root, "dist");

async function clean() {
  await rm(distDir, { recursive: true, force: true });
  await mkdir(distDir, { recursive: true });
}

async function bundleJS() {
  const entry = path.join(root, "js-src", "index.ts");

  await Promise.all([
    build({
      entryPoints: [entry],
      outfile: path.join(distDir, "aos.esm.js"),
      format: "esm",
      bundle: true,
      minify: true,
      sourcemap: false,
      target: "es2019",
    }),
    build({
      entryPoints: [entry],
      outfile: path.join(distDir, "aos.iife.js"),
      format: "iife",
      globalName: "AOSLite",
      bundle: true,
      minify: true,
      sourcemap: false,
      target: "es2019",
      banner: {
        js: "/*! aos-lite: lightweight animate-on-scroll */",
      },
    }),
  ]);
}

async function bundleCSS() {
  await build({
    entryPoints: [path.join(root, "js-src", "styles.css")],
    outfile: path.join(distDir, "aos.css"),
    loader: { ".css": "css" },
    bundle: true,
    minify: true,
  });
}

async function emitTypes() {
  await exec("npm run types", { cwd: root });
}

async function run() {
  await clean();
  await Promise.all([bundleJS(), bundleCSS(), emitTypes()]);
  console.log("Build complete.");
}

run().catch((error) => {
  console.error(error);
  process.exit(1);
});
