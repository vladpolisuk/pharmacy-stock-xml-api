const fields = [
  'balance_id', 'drug_name', 'batch_number',
  'expiry_date', 'quantity', 'storage_temperature',
];

const refreshButton = document.querySelector('#refresh');
const statusLine = document.querySelector('#status');
const tableBody = document.querySelector('#stock');

async function refreshStock() {
  refreshButton.disabled = true;
  statusLine.textContent = 'Загрузка…';
  tableBody.replaceChildren();

  try {
    const response = await fetch('/api/stock', {
      method: 'GET',
      headers: { Accept: 'application/xml' },
      cache: 'no-store',
    });
    const text = await response.text();
    const xml = new DOMParser().parseFromString(text, 'application/xml');
    if (xml.querySelector('parsererror')) {
      throw new Error(`Сервер вернул некорректный XML (HTTP ${response.status}).`);
    }
    if (!response.ok) {
      throw new Error(xml.querySelector('error > message')?.textContent
        || `Ошибка HTTP ${response.status}.`);
    }
    if (xml.documentElement.tagName !== 'stock_balances') {
      throw new Error('Ответ API имеет неожиданный формат.');
    }

    const records = [...xml.documentElement.children];
    const fragment = document.createDocumentFragment();
    for (const record of records) {
      const row = document.createElement('tr');
      for (const field of fields) {
        const cell = document.createElement('td');
        cell.textContent = record.querySelector(field)?.textContent ?? '';
        row.appendChild(cell);
      }
      fragment.appendChild(row);
    }
    tableBody.appendChild(fragment);
    statusLine.textContent = records.length
      ? `Записей: ${records.length}`
      : 'В базе пока нет остатков.';
  } catch (error) {
    statusLine.textContent = error.message || 'Не удалось загрузить данные.';
  } finally {
    refreshButton.disabled = false;
  }
}

refreshButton.addEventListener('click', refreshStock);
refreshStock();
