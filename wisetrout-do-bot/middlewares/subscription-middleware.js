import { Markup, Scenes } from "telegraf";
import { getModulesList } from "../api-calls/modules-list.js";
import { subscribe } from "../api-calls/subscription.js";

const scene = new Scenes.BaseScene('subscription-menu');

scene.enter(async ctx => {
    ctx.reply("Subscribe to:",
        Markup.inlineKeyboard([
        [
            Markup.button.callback("✅ All updates", "all"),
            Markup.button.callback("⚙️ Specific modules", "specific")
        ],
        [
            Markup.button.callback("🚫 Cancel", "cancel")
        ]
    ]))
});

scene.action("all", async ctx => {
    await Promise.all([
        ctx.reply('Subscribing to updates...'),
        subscribe(ctx.from.id)
    ]);

    ctx.session.subscribed = true;
    ctx.reply('Subscription successful!');
    ctx.scene.leave();
})

scene.action("specific", async ctx => {

    const [modules] = await Promise.all([
        getModulesList(),
        ctx.reply("Loading...")
    ])
    ;

    ctx.session.modules = modules;
    ctx.session.selectedModules = [];

    await ctx.reply('Please pick the modules you are interested in and click "subscribe".',
        Markup.inlineKeyboard(createKeyboardRows(modules))
    );
})

scene.action("complete", async ctx => {
    await Promise.all([
        ctx.reply('Subscribing to updates...'),
        subscribe(ctx.from.id, ctx.session.selectedModules)
    ]);
    ctx.session.subscribed = true;
    delete ctx.session.selectedModule;
    delete ctx.session.modules;
    ctx.reply('Subscription successful!');
    ctx.scene.leave();
})



scene.action("cancel", ctx => {
    ctx.reply("Subscription process cancelled");
    if(ctx.session.selectedModules) delete ctx.session.selectedModules;
    if(ctx.session.modules) delete ctx.session.modules;
    ctx.scene.leave();
});

scene.on("callback_query", async ctx => {
    const [prefix, action, moduleMachineName] = ctx.callbackQuery.data.split('--');
    if(prefix != "module") return;

    if(action === "select"){
        ctx.session.selectedModules.push(moduleMachineName);
    }else{
        ctx.session.selectedModules = ctx.session.selectedModules.filter(mn => mn != moduleMachineName);
    }
    ctx.editMessageReplyMarkup(
        {
            inline_keyboard: createKeyboardRows(ctx.session.modules, ctx.session.selectedModules)
        }
    );
});

const stage = new Scenes.Stage([scene]);
export const subscriptionMiddleware = stage.middleware();


function createKeyboardRows(allModules, selectedModules = []){
    const keyboardRows = [];

    allModules.forEach((module, moduleIndex) => {

        const {name, machine_name} = module;
        const moduleSelected = selectedModules.includes(machine_name);
        const btnText = `${moduleSelected ? "✔️ ":""}${name}`;
        const btnAction = `module--${moduleSelected ? "deselect":"select"}--${machine_name}`;

        const btn = Markup.button.callback(btnText, btnAction);

        if(moduleIndex % 3 === 0){
            keyboardRows.push([btn]);
        }else{
            keyboardRows[keyboardRows.length - 1].push(btn);
        }
    });

    keyboardRows.push([Markup.button.callback("📩 subscribe", "complete")]);
    keyboardRows.push([Markup.button.callback("🚫 Cancel", "cancel")]);

    return keyboardRows;
}