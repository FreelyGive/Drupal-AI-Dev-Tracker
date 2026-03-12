import { Markup } from "telegraf";

export const subBtn = Markup.button.callback('📬 Subscribe to updates', 'sub');
export const unsubBtn = Markup.button.callback('📭 Unsubscribe from all updates', 'unsub');
export const updateSubBtn = Markup.button.callback('⚙️ Change subscription', 'sub');
export const resubBtn = Markup.button.callback('📬 Resubscribe to updates', 'resub');
export const cancelBtn = Markup.button.callback("🚫 Cancel", "cancel");
export const wipeoutBtn = Markup.button.callback('🗑️ Wipe out my data', 'wipeout');
