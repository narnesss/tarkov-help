// Правила новых паролей повторяют серверную PasswordPolicy; сервер проверяет их повторно.
export function passwordError(password,username=''){
  if([...password].length<12||new TextEncoder().encode(password).length>72||/\p{C}/u.test(password))return 'Пароль: минимум 12 символов и максимум 72 байта, без управляющих символов.';
  if(!/[A-Z]/.test(password)||!/[a-z]/.test(password)||!/[0-9]/.test(password)||!/[\p{P}\p{S}]/u.test(password))return 'Добавь заглавную и строчную латинские буквы, цифру и спецсимвол.';
  const lower=password.toLowerCase(),plain=lower.replace(/[@013457$!]/g,char=>({'@':'a','0':'o','1':'i','3':'e','4':'a','5':'s','7':'t','$':'s','!':'i'})[char]);
  if(['password','qwerty','asdfgh','zxcvbn','letmein','welcome','admin','iloveyou','пароль','tarkov'].some(word=>lower.includes(word)||plain.includes(word)))return 'Не используй распространённые слова и комбинации вроде password, qwerty или 123456.';
  for(const row of ['0123456789','abcdefghijklmnopqrstuvwxyz','qwertyuiop','asdfghjkl','zxcvbnm']){
    for(const sequence of [row,[...row].reverse().join('')])for(let i=0;i<=sequence.length-6;i++)if(lower.includes(sequence.slice(i,i+6)))return 'Не используй последовательности из 6 символов и длиннее.';
  }
  if(/(.)\1{5}/us.test(password)||/(?:(?:0[1-9]|[12][0-9]|3[01])[-./]?(?:0[1-9]|1[0-2])[-./]?(?:19|20)[0-9]{2}|(?:19|20)[0-9]{2}[-./]?(?:0[1-9]|1[0-2])[-./]?(?:0[1-9]|[12][0-9]|3[01]))/.test(password))return 'Не используй даты и длинные повторы одинаковых символов.';
  const name=username.toLowerCase();if([...name].length>=4&&lower.includes(name))return 'Пароль не должен содержать твой логин.';
  return null;
}
