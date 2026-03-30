import { oAuthToken } from "../oauth.js";

export async function getModulesList(){
    const response = await fetch(process.env.BASE_URL + '/api/telegram/modules-list', {
        method: 'GET',
        headers: {
                    'Authorization': `Bearer ${oAuthToken}`
               }
    })

    if(!response.ok) throw new Error(response.status);

    const resObj = await response.json();

    const modules = [];
    for(const id in resObj){

        const { field_module_machine_name } = resObj[id];

        modules.push(field_module_machine_name[0].value);
    }

    modules.sort();
    

    return modules;
}

