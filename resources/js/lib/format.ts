export function usd(amount: number, decimals = 2): string {
    return '$' + amount.toFixed(decimals);
}

export function number(value: number): string {
    return value.toLocaleString('nl-NL');
}
