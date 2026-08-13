const fs = require('fs');
const transcript =
  'C:/Users/Krishna Rajbinde/.cursor/projects/c-Projects-ParamGoldSalesERP/agent-transcripts/3db6bf18-7a46-4a13-a4df-ed3e2f5d3d9c/3db6bf18-7a46-4a13-a4df-ed3e2f5d3d9c.jsonl';
const out =
  'C:/Projects/ParamGoldSalesERP/mobile/lib/modules/planning/screens/todays_planning_screen.dart';
const lines = fs.readFileSync(transcript, 'utf8').split(/\r?\n/);
const line = lines[238]; // 0-indexed line 239
const obj = JSON.parse(line);
for (const part of obj.message.content) {
  if (part.type === 'tool_use' && part.name === 'Write') {
    const contents = part.input.contents;
    console.log('path:', part.input.path);
    console.log('len:', contents.length);
    console.log('has_BottomComposer:', contents.includes('_BottomComposer'));
    console.log('has_TaskRow:', contents.includes('_TaskRow'));
    console.log('has_header:', contents.includes('Microsoft To Do'));
    fs.writeFileSync(out, contents, 'utf8');
    console.log('written_ok');
  }
}
