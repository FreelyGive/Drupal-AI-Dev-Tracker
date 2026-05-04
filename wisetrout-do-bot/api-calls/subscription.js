import { sendAuthorizedRequest } from "../oauth.js";

export async function subscribe(userInfo, modules, type){
    console.log('Creating subscription...');
    console.log(userInfo);
    console.log('Modules:');
    console.log(modules);

    const body = JSON.stringify({userInfo: {...userInfo, type}, modules});

    console.log('Body:');
    console.log(body);


    const res = await sendAuthorizedRequest(process.env.BASE_URL + '/api/telegram/subscribe',  {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
    },
    body
    });

    console.log(res);

}

export async function updateStatus(chatId, subscribed){
    console.log('Updating status...');

    const body = JSON.stringify({ chatId, subscribed });

    console.log('Body:');
    console.log(body);

    const res = await sendAuthorizedRequest(process.env.BASE_URL + '/api/telegram/update-status',  {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body
    });


    console.log(res);

}

export async function checkSubscription(chatId){
    const res = await sendAuthorizedRequest(`${process.env.BASE_URL}/api/telegram/user-info/${chatId}`,  {
        method: 'GET'
    });

    console.log('user Info success!');
    console.log(res);

    if(res.status === 204) return null;

    let data;
    try {
        data = JSON.parse(text);
    } catch (e) {
        console.error('Failed to parse JSON. Response text was:', text);
        throw new Error('Invalid JSON response from server');
    }

    console.log('User info:');
    console.log(data);

    return data;
}

export async function wipeoutData(chatId){
    console.log('Wipeout...');

    const body = JSON.stringify({ chatId });

    console.log('Body:');
    console.log(body);

    const res = await sendAuthorizedRequest(process.env.BASE_URL + '/api/telegram/wipeout',  {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body
    });

    console.log('Success!');
    console.log(res);
}
