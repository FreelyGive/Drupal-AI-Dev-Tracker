import { Markup, Scenes } from "telegraf";
import { wipeoutData } from "../api-calls/subscription.js";

export const scene = new Scenes.BaseScene('wipeout');

scene.enter(async ctx => {
    ctx.reply("⚠️ Are you sure you would like to wipe out all of your data? This action will clear the list of modules you are interested in. If you decide to resubscribe, you will need to select them again.",
        Markup.inlineKeyboard([
        [
            Markup.button.callback("✅ Delete my data", "delete")
        ],
        [
            Markup.button.callback("🚫 Cancel", "cancel")
        ]
    ]))
});

scene.action("delete", async ctx => {
    await Promise.all([
        ctx.reply('Clearing your data...'),
        wipeoutData(ctx.from.id)
    ]);

    ctx.session.userInfo = null;
    ctx.reply('Cleared successfully!');
    ctx.scene.leave();
})

scene.action("cancel", ctx => {
    ctx.reply("Deletion cancelled");
    ctx.scene.leave();
});