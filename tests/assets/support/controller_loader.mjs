import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const root = resolve(import.meta.dirname, '../../..');

export async function loadStimulusController(path) {
    const absolutePath = resolve(root, path);
    let source = await readFile(absolutePath, 'utf8');

    source = source.replace(
        /import\s+\{\s*Controller\s*\}\s+from\s+['"]@hotwired\/stimulus['"];\s*/,
        stimulusControllerStub(),
    );
    source = source.replace(
        /from\s+['"](\.{1,2}\/[^'"]+)['"]/g,
        (match, specifier) => `from '${pathToFileURL(resolve(dirname(absolutePath), specifier)).href}'`,
    );

    return import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
}

function stimulusControllerStub() {
    return `
class Controller {
    dispatch(name, options = {}) {
        this.element?.dispatchEvent?.(new CustomEvent(name, {
            bubbles: true,
            detail: options.detail || {},
        }));
    }
}
`;
}
