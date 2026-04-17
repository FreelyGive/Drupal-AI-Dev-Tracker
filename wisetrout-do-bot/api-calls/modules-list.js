import { sendAuthorizedRequest } from "../oauth.js";

export async function getModulesList(){

    const response = await sendAuthorizedRequest(process.env.BASE_URL + '/api/telegram/modules-list', {
        method: 'GET',
    })

    const modules = await response.json();

    modules.sort();
    
    return modules;
}

