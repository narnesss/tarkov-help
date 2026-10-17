// Контактная анкета не участвует в идентификации аккаунта.
export function profileFields(values){
  const data={};
  for(const field of ['display_name','phone','country','city','gender','age'])data[field]=String(values[field]??'').trim();
  data.phone=data.phone.replace(/[ ()-]/g,'');
  for(const [field,max] of [['display_name',64],['country',80],['city',100]]){
    if((field==='display_name'&&!data[field])||[...data[field]].length>max||/[\p{C}<>]/u.test(data[field])){
      throw Object.assign(new Error(`Допустимо ${field==='display_name'?'от 1 до':'не более'} ${max} символов, без HTML и управляющих знаков.`),{fields:[field]});
    }
  }
  if(data.phone&&!/^\+[1-9][0-9]{6,14}$/.test(data.phone))throw Object.assign(new Error('Телефон: + и от 7 до 15 цифр.'),{fields:['phone']});
  if(!['','male','female','other'].includes(data.gender))throw Object.assign(new Error('Выбери пол из списка.'),{fields:['gender']});
  if(data.age&&!/^(?:[1-9]|[1-9][0-9]|1[01][0-9]|120)$/.test(data.age))throw Object.assign(new Error('Возраст: целое число от 1 до 120.'),{fields:['age']});
  for(const field of ['phone','country','city','gender','age'])if(!data[field])data[field]=null;
  if(data.age!==null)data.age=Number(data.age);
  return data;
}
