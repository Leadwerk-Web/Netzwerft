import { cp, lstat, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';

const root = process.cwd();
const output = path.join(root, '_site');
const repository = String(process.env.GITHUB_REPOSITORY || 'Leadwerk-Web/Netzwerft');
const repositoryName = repository.split('/').at(-1) || '';

if (!/^[A-Za-z0-9_.-]{1,100}$/u.test(repositoryName)) {
  throw new Error('GITHUB_REPOSITORY does not contain a safe repository name.');
}

const basePath = `/${repositoryName}/`;
const sourcePages = [
  't2med-wechsel-v2.html',
  'danke.html',
  'impressum.html',
  'datenschutz.html',
  '404.html',
];
const assetRoots = ['css', 'js', 'fonts', 'Fotos', 'svg'];

function prepareHtml(source, { routeDepth = 0 } = {}) {
  let html = source.replace(
    /<meta\s+name=(['"])robots\1\s+content=(['"])[^'"]*\2\s*\/?\s*>/iu,
    '<meta name="robots" content="noindex, nofollow">',
  );
  if (!/<meta\s+name=(['"])robots\1/iu.test(html)) {
    html = html.replace(
      /(<meta\s+charset=(['"])[^'"]+\2\s*\/?\s*>)/iu,
      '$1\n    <meta name="robots" content="noindex, nofollow">',
    );
  }

  html = html.replace(
    /\b(href|src|poster|action)=(['"])\/(?!\/)/giu,
    (_match, attribute, quote) => `${attribute}=${quote}${basePath}`,
  );
  html = html.replace(
    /\b(srcset)=(['"])([^'"]*)\2/giu,
    (_match, attribute, quote, value) => {
      const rewritten = value.replace(/(^|,\s*)\/(?!\/)/gu, `$1${basePath}`);
      return `${attribute}=${quote}${rewritten}${quote}`;
    },
  );
  html = html.replace(/url\((['"]?)\/(?!\/)/giu, `url($1${basePath}`);

  if (routeDepth > 0) {
    const prefix = '../'.repeat(routeDepth);
    html = html.replace(
      /\b(href|src|poster|action)=(['"])(?![a-z][a-z0-9+.-]*:|#|\/|\.\.?\/)([^'"]+)\2/giu,
      (_match, attribute, quote, value) => `${attribute}=${quote}${prefix}${value}${quote}`,
    );
    html = html.replace(
      /\b(srcset)=(['"])([^'"]*)\1/giu,
      (_match, quote, value) => {
        const rewritten = value.split(',').map((candidate) => {
          const trimmed = candidate.trim();
          if (!trimmed || /^(?:[a-z][a-z0-9+.-]*:|#|\/|\.\.?\/)/iu.test(trimmed)) return trimmed;
          return `${prefix}${trimmed}`;
        }).join(', ');
        return `srcset=${quote}${rewritten}${quote}`;
      },
    );
  }

  return html;
}

async function assertRegularFile(relativePath) {
  const info = await lstat(path.join(root, relativePath));
  if (!info.isFile() || info.isSymbolicLink() || info.size < 1 || info.size > 5_000_000) {
    throw new Error(`Pages source is not a bounded regular file: ${relativePath}`);
  }
}

await rm(output, { recursive: true, force: true });
await mkdir(output, { recursive: true });

for (const directory of assetRoots) {
  const info = await lstat(path.join(root, directory));
  if (!info.isDirectory() || info.isSymbolicLink()) {
    throw new Error(`Pages asset root is not a regular directory: ${directory}`);
  }
  await cp(path.join(root, directory), path.join(output, directory), {
    recursive: true,
    dereference: false,
    errorOnExist: true,
  });
}

for (const page of sourcePages) {
  await assertRegularFile(page);
  const source = await readFile(path.join(root, page), 'utf8');
  await writeFile(path.join(output, page), prepareHtml(source), 'utf8');
}

const home = await readFile(path.join(root, 't2med-wechsel-v2.html'), 'utf8');
await writeFile(path.join(output, 'index.html'), prepareHtml(home), 'utf8');

for (const route of ['danke', 'impressum', 'datenschutz']) {
  const source = await readFile(path.join(root, `${route}.html`), 'utf8');
  const directory = path.join(output, route);
  await mkdir(directory, { recursive: true });
  await writeFile(path.join(directory, 'index.html'), prepareHtml(source, { routeDepth: 1 }), 'utf8');
}

await writeFile(path.join(output, '.nojekyll'), '', 'utf8');

const rendered = await Promise.all([
  'index.html', 'danke/index.html', 'impressum/index.html', 'datenschutz/index.html', '404.html',
].map((file) => readFile(path.join(output, file), 'utf8')));
const escapedRepositoryName = repositoryName.replace(/[.*+?^${}()|[\]\\]/gu, '\\$&');
const escapedRootRelative = new RegExp(
  `\\b(?:href|src|poster|action)=(['"])\\/(?!\\/|${escapedRepositoryName}/)`,
  'iu',
);
if (rendered.some((html) => escapedRootRelative.test(html))) {
  throw new Error('A root-relative URL escaped the GitHub Pages project-base rewrite.');
}

console.log(`Prepared GitHub Pages preview at ${basePath} (${rendered.length} verified routes).`);
