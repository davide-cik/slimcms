/**
 * Il negozio lato sito. Per ora solo la formattazione dei prezzi; il passo 2
 * ci aggiunge il carrello.
 */

const formato = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });

/** 3900 -> "39,00 €". I prezzi viaggiano in centesimi, mai in float. */
export const euro = (centesimi: number): string => formato.format(centesimi / 100);
