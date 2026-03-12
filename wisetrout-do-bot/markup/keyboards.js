import { Markup } from "telegraf";
import { resubBtn, subBtn, unsubBtn, updateSubBtn, wipeoutBtn } from "./buttons.js";

export function createActionsKeyboard(ctx){
    let keyboardRows;

    if(ctx.session.userInfo){
        if(ctx.session.userInfo.subscribed){
            keyboardRows = [[updateSubBtn], [unsubBtn]];
        }else{
            keyboardRows = [[resubBtn, wipeoutBtn]];
        }
    }else{
        keyboardRows = [[subBtn]];
    }

    return Markup.inlineKeyboard(keyboardRows);
}