package htaccesswatch

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/fsnotify/fsnotify"
)

const (
	httpdocsDir      = "httpdocs"
	htaccessFile     = ".htaccess"
	maxHttpdocsDepth = 15
)

type Event struct {
	Key       string
	Domain    string
	VhostRoot string
	Path      string
	Op        fsnotify.Op
	When      time.Time
}

type Watcher struct {
	root    string
	watcher *fsnotify.Watcher

	events chan Event
	errors chan error

	mu     sync.Mutex
	paths  map[string]struct{}
	roots  map[string]struct{}
	closed bool
	wg     sync.WaitGroup
}

func New(root string) (*Watcher, error) {
	cleanRoot := filepath.Clean(root)
	if "" == cleanRoot {
		return nil, fmt.Errorf("watch root is required")
	}

	fw, err := fsnotify.NewWatcher()
	if err != nil {
		return nil, err
	}

	w := &Watcher{
		root:    cleanRoot,
		watcher: fw,
		events:  make(chan Event, 256),
		errors:  make(chan error, 32),
		paths:   make(map[string]struct{}),
		roots:   make(map[string]struct{}),
	}

	w.wg.Add(1)
	go func() {
		defer w.wg.Done()
		w.forward()
	}()

	return w, nil
}

func (w *Watcher) Close() error {
	w.mu.Lock()
	if w.closed {
		w.mu.Unlock()
		return nil
	}
	w.closed = true
	err := w.watcher.Close()
	w.mu.Unlock()

	w.wg.Wait()
	close(w.events)
	close(w.errors)
	return err
}

func (w *Watcher) Events() <-chan Event {
	return w.events
}

func (w *Watcher) Errors() <-chan error {
	return w.errors
}

func (w *Watcher) SyncRoots(roots []string) error {
	allowed := make(map[string]struct{}, len(roots))
	for _, root := range roots {
		clean := filepath.Clean(root)
		relative, err := filepath.Rel(w.root, clean)
		if err != nil || relative == "." || strings.HasPrefix(relative, ".."+string(os.PathSeparator)) || relative == ".." {
			return fmt.Errorf("watch root is outside %s: %s", w.root, root)
		}
		allowed[clean] = struct{}{}
	}

	w.mu.Lock()
	for path := range w.paths {
		if !pathWithinRoots(path, allowed) {
			_ = w.watcher.Remove(path)
			delete(w.paths, path)
		}
	}
	w.roots = allowed
	w.mu.Unlock()

	for root := range allowed {
		if err := filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
			if err != nil || info == nil {
				return nil
			}
			if !info.IsDir() {
				return nil
			}
			if w.isTooDeepWatchedDir(path) {
				return filepath.SkipDir
			}
			if !w.isWatchedDir(path) {
				return nil
			}
			return w.addDir(path)
		}); err != nil {
			return err
		}
	}
	return nil
}

func (w *Watcher) forward() {
	for {
		select {
		case event, ok := <-w.watcher.Events:
			if !ok {
				return
			}
			w.handle(event)
		case err, ok := <-w.watcher.Errors:
			if !ok {
				return
			}
			w.emitError(err)
		}
	}
}

func (w *Watcher) handle(event fsnotify.Event) {
	if hasOp(event, fsnotify.Create) {
		if info, err := os.Stat(event.Name); err == nil && info.IsDir() && w.isWatchedDir(event.Name) {
			if err := w.addDir(event.Name); err != nil {
				w.emitError(err)
			}
		}
	}

	if filepath.Base(event.Name) != htaccessFile {
		return
	}
	if !hasOp(event, fsnotify.Create) &&
		!hasOp(event, fsnotify.Write) &&
		!hasOp(event, fsnotify.Remove) &&
		!hasOp(event, fsnotify.Rename) {
		return
	}

	vhostRoot, domain := w.vhostRoot(event.Name)
	w.emit(Event{
		Key:       vhostRoot,
		Domain:    domain,
		VhostRoot: vhostRoot,
		Path:      event.Name,
		Op:        event.Op,
		When:      time.Now().UTC(),
	})
}

func hasOp(event fsnotify.Event, op fsnotify.Op) bool {
	return event.Op&op != 0
}

func (w *Watcher) addDir(path string) error {
	clean := filepath.Clean(path)
	w.mu.Lock()
	defer w.mu.Unlock()

	if w.closed {
		return nil
	}
	if _, ok := w.paths[clean]; ok {
		return nil
	}
	if err := w.watcher.Add(clean); err != nil {
		return err
	}
	w.paths[clean] = struct{}{}
	return nil
}

func (w *Watcher) emit(event Event) {
	w.mu.Lock()
	defer w.mu.Unlock()
	closed := w.closed
	if closed {
		return
	}
	select {
	case w.events <- event:
	default:
		return
	}
}

func (w *Watcher) emitError(err error) {
	w.mu.Lock()
	defer w.mu.Unlock()
	closed := w.closed
	if closed {
		return
	}
	select {
	case w.errors <- err:
	default:
		return
	}
}

func (w *Watcher) isWatchedDir(path string) bool {
	clean := filepath.Clean(path)
	w.mu.Lock()
	defer w.mu.Unlock()
	for root := range w.roots {
		relative, err := filepath.Rel(root, clean)
		if err != nil || relative == ".." || strings.HasPrefix(relative, ".."+string(os.PathSeparator)) {
			continue
		}
		if relative == "." {
			return true
		}
		return len(strings.Split(relative, string(os.PathSeparator))) <= maxHttpdocsDepth
	}
	return false
}

func (w *Watcher) isTooDeepWatchedDir(path string) bool {
	clean := filepath.Clean(path)
	w.mu.Lock()
	defer w.mu.Unlock()
	for root := range w.roots {
		relative, err := filepath.Rel(root, clean)
		if err != nil || relative == ".." || strings.HasPrefix(relative, ".."+string(os.PathSeparator)) {
			continue
		}
		return relative != "." && len(strings.Split(relative, string(os.PathSeparator))) > maxHttpdocsDepth
	}
	return false
}

func pathWithinRoots(path string, roots map[string]struct{}) bool {
	for root := range roots {
		relative, err := filepath.Rel(root, path)
		if err == nil && relative != ".." && !strings.HasPrefix(relative, ".."+string(os.PathSeparator)) {
			return true
		}
	}
	return false
}

func (w *Watcher) vhostRoot(path string) (string, string) {
	parent := filepath.Clean(filepath.Dir(path))
	needle := string(os.PathSeparator) + httpdocsDir
	idx := strings.LastIndex(parent, needle)
	if idx == -1 {
		return parent, filepath.Base(parent)
	}

	root := parent[:idx]
	if "" == root {
		root = string(os.PathSeparator)
	}
	return root, filepath.Base(root)
}
