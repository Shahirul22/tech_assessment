// How a log entry reads on screen (FR3, FR3.1, FR1.4, FR1.8, AC-US3.2, AC-US3.3).
//
// The four kinds of plan §6 all render here.
// Labels are words, so the kind is clear without colour or icons.

/**
 * @param {object} entry  a log entry as the API sends it
 * @param {number|string|null} viewerId  the operator looking at the screen
 * @returns {{label: string, text: string, own: boolean}}
 */
export function renderEntry(entry, viewerId) {
    switch (entry.kind) {
        case 'allocation':
            return {
                label: 'Allocation',
                text: allocationText(entry),
                own: isOwn(entry.operator, viewerId),
            };

        case 'restock':
            return { label: 'Restock', text: restockText(entry), own: false };

        case 'correction':
            return { label: 'Correction', text: correctionText(entry), own: false };

        case 'expiry':
            return {
                label: 'Expiry',
                text: `${entry.operator.name}: ${units(entry.units)} returned (#${entry.allocation_seq})`,
                own: isOwn(entry.operator, viewerId),
            };

        default:
            return { label: 'Entry', text: `Unknown entry #${entry.seq}`, own: false };
    }
}

function isOwn(operator, viewerId) {
    return viewerId !== null && viewerId !== undefined && String(operator.id) === String(viewerId);
}

function allocationText({ operator, requested, granted, type }) {
    const suffix = type === 'temporary' ? ' · temporary' : '';

    if (granted <= 0) {
        return `${operator.name} requested ${requested}, granted 0${suffix}`;
    }

    if (granted >= requested) {
        return `${operator.name}: ${granted} granted${suffix}`;
    }

    return `${operator.name}: ${granted} of ${requested} granted${suffix}`;
}

function restockText({ amount, note }) {
    const base = `+${units(amount)}`;

    return note ? `${base} (${note})` : base;
}

function correctionText({ supervisor, allocation_seq: seq, change, reason }) {
    if (change.to === null) {
        return `${supervisor.name} voided #${seq} (was ${change.from}): ${reason}`;
    }

    return `${supervisor.name} changed #${seq} from ${change.from} to ${change.to}: ${reason}`;
}

function units(count) {
    return `${count} ${count === 1 ? 'unit' : 'units'}`;
}
