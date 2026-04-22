import { getOAuthToken } from "./api-calls/oauth.js";

let oAuthToken = null;

export async function activateOAuthToken(){

    const responseData = await getOAuthToken();

    oAuthToken = responseData.access_token;
    
}

export async function sendAuthorizedRequest(url, { method, body, headers }){

    const options = { method };

    if(body) options.body = body;
    options.headers = headers ?
    {...headers, 'Authorization': `Bearer ${oAuthToken}`} :
    {'Authorization': `Bearer ${oAuthToken}`};

    try{
        const response = await fetch(url, options);

        if(response.status === 401){
            console.log('Token expired, getting new...');
            
            const oauthData = await getOAuthToken();
            oAuthToken = oauthData.access_token;
            const newResponse = await sendAuthorizedRequest(url, {method, body});
            return newResponse;
        }

        if(!response.ok) {
            const errorText = await response.text();
            throw new Error(`HTTP ${response.status}: ${errorText}`);
        }

        return response;

    }catch(err){
        console.log(`Could not access ${url}: ${err}`);
    }
}