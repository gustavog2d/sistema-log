import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';

const ROOT = join(__dirname, '..');
const TEXT_ATTRIBUTES = ['aria-label', 'title', 'placeholder', 'alt', 'label'];

function files(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name);

    if (entry.isDirectory()) {
      return files(path);
    }

    return path.endsWith('.tsx') && !path.endsWith('.test.tsx') ? [path] : [];
  });
}

/** Textos fixos em JSX: texto solto entre tags ou atributo de texto com literal. */
function hardcodedTextsIn(code: string): string[] {
  const source = ts.createSourceFile(
    'x.tsx',
    code,
    ts.ScriptTarget.ES2022,
    true,
    ts.ScriptKind.TSX,
  );
  const found: string[] = [];

  const visit = (node: ts.Node) => {
    if (ts.isJsxText(node) && /\p{L}{2,}/u.test(node.text)) {
      found.push(node.text.trim());
    }

    if (
      ts.isJsxAttribute(node) &&
      TEXT_ATTRIBUTES.includes(node.name.getText()) &&
      node.initializer &&
      ts.isStringLiteral(node.initializer) &&
      /\p{L}{2,}/u.test(node.initializer.text)
    ) {
      found.push(node.initializer.text);
    }

    ts.forEachChild(node, visit);
  };
  visit(source);

  return found;
}

function hardcodedTexts(path: string): string[] {
  return hardcodedTextsIn(readFileSync(path, 'utf8'));
}

describe('no hardcoded text', () => {
  const targets = [
    ...files(join(ROOT, 'ui')),
    ...files(join(ROOT, 'layout')),
    ...files(join(ROOT, 'site')),
    ...files(join(ROOT, 'panel')),
  ];

  it('finds the components to check', () => {
    expect(targets.length).toBeGreaterThan(10);
  });

  it.each(targets.map((path) => [path.replace(ROOT, ''), path]))(
    'CA-08 %s has no fixed Portuguese text',
    (_name, path) => {
      expect(hardcodedTexts(path as string)).toEqual([]);
    },
  );

  it('CA-08 would catch a fixed text (self-check of the scanner)', () => {
    expect(hardcodedTextsIn('<p title="Olá">Texto fixo</p>')).toEqual([
      'Olá',
      'Texto fixo',
    ]);
    expect(hardcodedTextsIn('<p title={t("a")}>{t("b")}</p>')).toEqual([]);
  });
});
