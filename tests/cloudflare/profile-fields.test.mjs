import {test} from 'node:test';
import assert from 'node:assert/strict';
import {profileFields} from '../../public/profile-fields.mjs';

test('анкета нормализует текст и телефон, сохраняет необязательность полей',()=>{
  assert.deepEqual(profileFields({display_name:'  Игрок  ',phone:'+7 (999) 123-45-67',country:' Россия ',city:' Москва ',gender:'male',age:'30'}),
    {display_name:'Игрок',phone:'+79991234567',country:'Россия',city:'Москва',gender:'male',age:30});
  assert.deepEqual(profileFields({display_name:'Игрок'}),{display_name:'Игрок',phone:null,country:null,city:null,gender:null,age:null});
});

test('клиент отклоняет HTML, управляющие символы и неверные значения',()=>{
  for(const [field,value] of [['display_name',' '],['display_name','<img src=x onerror=alert(1)>'],['city','<script>x</script>'],['country','Россия\u202E'],['phone','79991234567'],['phone','+0123456789'],['gender','unknown'],['age','0'],['age','121'],['age','30.5']]){
    assert.throws(()=>profileFields({display_name:'Игрок',[field]:value}),error=>error.fields[0]===field);
  }
});
