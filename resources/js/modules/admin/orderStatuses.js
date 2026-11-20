// Spanish labels of the order states. The ONE list lives in PHP (OrderStatusService::LABELS) and arrives with
// GET /admin/session; the panel stores it here once, so no component keeps its own copy. Only the colours are the frontend's.
let labels = {};

export const setOrderStatuses = map => { labels = map || {}; };
export const orderStatusLabel = value => labels[value] ?? value;
export const orderStatusTone = value => ({ pending: 'amber', confirmed: 'green', preparing: 'blue', ready: 'teal', shipped: 'purple', delivered: 'green', cancelled: 'red' })[value] ?? 'gray';
