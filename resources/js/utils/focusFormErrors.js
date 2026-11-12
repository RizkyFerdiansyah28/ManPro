export default function focusFormErrors(form, summary, errors) {
    if (!Object.keys(errors).length) return false;
    const target = Object.keys(errors).map((name) => form?.elements.namedItem(name))
        .find((field) => !field?.disabled && typeof field?.focus === 'function');
    const destination = target ?? summary;
    destination?.focus();
    return Boolean(destination);
}
