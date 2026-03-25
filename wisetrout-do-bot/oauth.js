
const TOKEN_EXPIRATION_MS = 5 * 60 * 1000;

import { getOAuthToken } from "./api-calls/oauth.js";

export let oAuthToken = null;

export async function activateOAuthToken(){

    oAuthToken = await getOAuthToken();
    
    setInterval(async function(){
        oAuthToken = await getOAuthToken();
    }, TOKEN_EXPIRATION_MS);

    return oAuthToken;
    
}