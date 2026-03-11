import { Markup} from 'telegraf';


export default function startHandler(ctx){

    const subBtn = Markup.button.callback('📬 ' + 'Subscribe to updates', 'sub');
    const unsubBtn = Markup.button.callback('📭 ' + 'Unsubscribe from all updates', 'unsub');
    const updateSubBtn = Markup.button.callback('⚙️ ' + 'Change subscription', 'sub');
    
    const keyboardRows = ctx.session.subscribed ?
    [[updateSubBtn], [unsubBtn]]:
    [[subBtn]]
    
        ctx.reply(
            'Welcome to the Wisetrout Drupal org bot!', 
            Markup.inlineKeyboard(keyboardRows)
        )
}