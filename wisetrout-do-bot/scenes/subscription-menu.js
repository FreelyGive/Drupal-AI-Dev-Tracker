import { Markup, Scenes } from "telegraf";
import { getModulesList } from "../api-calls/modules-list.js";
import { subscribe } from "../api-calls/subscription.js";

const MODULES_PER_PAGE = 10;

export const scene = new Scenes.BaseScene('subscription-menu');

scene.enter(async ctx => {

    try{
        const [modules] = await Promise.all([
            getModulesList(),
            ctx.reply("Loading...")
        ]);
        

        ctx.session.modules = modules;
        ctx.session.selectedModules = ctx.session.userInfo ? ctx.session.userInfo.modules : [];
        ctx.session.page = 0;

        await ctx.reply('Please pick the modules you are interested in and click "subscribe"');

        const messageData = await ctx.reply(createModulesList(ctx), Markup.inlineKeyboard(createMenuRows(ctx)));

        const { message_id } = messageData;
        

        ctx.session.menuMessageId = message_id;
        
    }catch(err){
        ctx.reply('Error');
        ctx.reply(err);
    }

    
})

scene.action("page_back", ctx => {
    ctx.session.page--;
    refreshMenu(ctx);

})

scene.action("page_next", ctx => {
    ctx.session.page++;
    refreshMenu(ctx);
})

scene.command(/.+/, async ctx => {
    const { command } = ctx;

    if(['page_back', 'page_next', 'save', 'nothing', 'select_all', 'select_none'].includes(command)) return;
    

    if(ctx.session.selectedModules.includes(command)){
        ctx.session.selectedModules = ctx.session.selectedModules.filter(m => m != command);
    }else{
        ctx.session.selectedModules.push(command);
    }

    refreshMenu(ctx);

    ctx.deleteMessage();
})

scene.action("select_all", async ctx => {
    ctx.session.selectedModules = [...ctx.session.modules];

    refreshMenu(ctx);

})

scene.action('select_none', ctx => {
    ctx.session.selectedModules = [];
    refreshMenu(ctx);
})


scene.action("save", async ctx => {

    await Promise.all([
        ctx.reply('Subscribing to updates..'),
        subscribe(ctx.from, ctx.session.selectedModules)
    ]);
    ctx.session.userInfo = {
        subscribed: true,
        modules: ctx.session.selectedModules
    }
    delete ctx.session.selectedModules;
    delete ctx.session.modules;
    delete ctx.session.menuMessageId;
    ctx.reply('Subscription successful!');
    ctx.scene.leave();
})

function createModulesList(ctx){
    let message = '';
    const firstModuleIndex = ctx.session.page * MODULES_PER_PAGE;
    const lastModuleIndex = (ctx.session.page + 1) * MODULES_PER_PAGE;
    
    
    const modulesOnPage = ctx.session.modules
    .slice(firstModuleIndex, lastModuleIndex)
    .map(m => ({
        name: m,
        checked: ctx.session.selectedModules.includes(m)
    }))

    modulesOnPage.forEach(m => {
        message += `${m.checked ?'✅': '🟡'}/${m.name}\n`;
    })

    return message;

}

function createMenuRows(ctx){ 

    const backBtn = ctx.session.page === 0 ?
    Markup.button.callback(" ", "nothing") :
    Markup.button.callback("⬅️", "page_back");

    const lastPageIndex = Math.ceil(ctx.session.modules.length / MODULES_PER_PAGE) - 1;

    const forwardButton = ctx.session.page === lastPageIndex ?
    Markup.button.callback(" ", "nothing") :
    Markup.button.callback("➡️", "page_next")

    return [
            [
                Markup.button.callback("✅ Select all", "select_all"),
                Markup.button.callback("❌ Select none", "select_none"),
            ],
            [
                backBtn,
                Markup.button.callback("✔️ Save", "save"),
                forwardButton
            ]
        ];
}

function refreshMenu(ctx){
    try{
        ctx.editMessageText(createModulesList(ctx), {
        message_id: ctx.session.menuMessageId,
        reply_markup: {
            inline_keyboard: createMenuRows(ctx)
        }
    });
    }catch(err){
        console.log(err);
        
    }
    
}