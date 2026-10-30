// src/features/booking/receiptToken.ts
// Token del recibo de reserva: viaja en el fragmento (#), que el navegador no
// envía al servidor ni en la cabecera Referer. El query `token` se sigue
// leyendo para no romper recibos/enlaces ya emitidos.

export function receiptUrl(base: string, cartId: string, token: string): string {
  const query = `?bookingId=${encodeURIComponent(cartId)}`;
  return token ? `${base}${query}#token=${encodeURIComponent(token)}` : `${base}${query}`;
}

export function readReceiptToken(search: string, hash: string): string {
  const fromHash = new URLSearchParams(hash.replace(/^#/, '')).get('token');
  if (fromHash) return fromHash;
  return new URLSearchParams(search).get('token') || '';
}
