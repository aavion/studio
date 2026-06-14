export function selectorListMatches(element, selector) {
    return selector.split(',').some((part) => selectorMatches(element, part.trim()));
}

function selectorMatches(element, selector) {
    if (!selector) {
        return false;
    }

    if (selector.startsWith('#')) {
        return element.id === selector.slice(1);
    }

    if (selector.startsWith('.')) {
        return element.classList.contains(selector.slice(1));
    }

    const dataAttribute = selector.match(/^\[data-([a-z0-9-]+)(?:=["']?([^"'\]]+)["']?)?\]$/i);
    if (dataAttribute) {
        const value = element.dataset[dataName(`data-${dataAttribute[1]}`)];

        return dataAttribute[2] === undefined ? value !== undefined : value === dataAttribute[2];
    }

    const nameAttribute = selector.match(/^\[name=["']?([^"'\]]+)["']?\]$/i);
    if (nameAttribute) {
        return element.name === nameAttribute[1];
    }

    const tagNameAttribute = selector.match(/^([a-z0-9]+)\[name=["']?([^"'\]]+)["']?\]$/i);
    if (tagNameAttribute) {
        return element.tagName === tagNameAttribute[1].toUpperCase() && element.name === tagNameAttribute[2];
    }

    const buttonType = selector.match(/^button\[type=["']?([^"'\]]+)["']?\]$/i);
    if (buttonType) {
        return element.tagName === 'BUTTON' && element.type === buttonType[1];
    }

    return element.tagName === selector.toUpperCase();
}

function dataName(attribute) {
    return attribute.slice(5).replace(/-([a-z])/g, (match, letter) => letter.toUpperCase());
}
