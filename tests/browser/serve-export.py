from http.server import ThreadingHTTPServer,SimpleHTTPRequestHandler
from pathlib import Path
root=Path(__file__).resolve().parents[2]/'dist'
class Handler(SimpleHTTPRequestHandler):
 def __init__(self,*args,**kw):super().__init__(*args,directory=str(root),**kw)
 def do_GET(self):
  clean=self.path.split('?',1)[0]
  if clean=='/':self.path='/index.html'
  elif (root/(clean.strip('/')+'.html')).is_file():self.path=clean+'.html'
  super().do_GET()
 def log_message(self,*args):pass
ThreadingHTTPServer(('0.0.0.0',8111),Handler).serve_forever()
